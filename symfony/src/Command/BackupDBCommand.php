<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Process\Process;

class BackupDBCommand extends Command
{
	protected static $defaultName = 'app:backupdb';
	private LoggerInterface $logger;
	private $configs;
	private $cacheDir;
	private $backupdir;
	private MailerInterface $mailer;
	private $emailsNotif;

	protected function configure(): void
	{
		$this
			->addOption('serveur', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Serveur à considérer')
			->addOption('status', null, InputOption::VALUE_NONE, 'Affiche le statut des serveurs');
	}

	public function __construct(LoggerInterface $logger, $configs, $cacheDir, $backupdir, MailerInterface $mailer, $emailsNotif)
	{
		parent::__construct();
		$this->logger = $logger;
		$this->configs = $configs;
		$this->cacheDir = $cacheDir;
		$this->backupdir = $backupdir;
		$this->mailer = $mailer;
		$this->emailsNotif = (array)$emailsNotif;
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{


		if (!is_array($this->configs)) {
			throw new \Exception('Aucun serveur détecté');
		}

		$serveurs = $input->getOption('serveur');
		if (count($serveurs) > 0) {
			foreach ($serveurs as $serveur) {
				if (!array_key_exists($serveur, $this->configs)) {
					throw new \Exception(sprintf('Serveur %1$s demandé mais non configuré (parmi %2$s)', $serveur, implode(';', array_keys($this->configs))));
				}
			}
			$this->configs = array_intersect_key($this->configs, array_fill_keys($serveurs, ''));
		}

		if ($input->getOption('status') === true) {
			// on souhaite uniquement afficher le statut du serveur
			foreach ($this->configs as $kConfig => $config) {

				$output->writeln($kConfig);

				$params = [
					'CONTAINER' => $config['container'],
					'USER' => $config['user'],
				];
				if(!empty($config['passwordfile'])) {
					$params['PASS'] = file_get_contents($config['passwordfile']);
				} else {
					$params['PASS'] = $config['password'];
				}

				$process = Process::fromShellCommandline('docker exec ${CONTAINER} mysql -u${USER} -p${PASS} -e "SHOW SLAVE STATUS\G"');
				$process->mustRun(null, $params);
				$output->writeln($process->getOutput());

			}
			return Command::SUCCESS;
		}


		$lockFile = $this->cacheDir."/backup.lock";
		$gotLock = false;
		if($LOCK = fopen($lockFile, "w+")) {
			if(flock($LOCK, LOCK_EX | LOCK_NB)) {
				$gotLock = true;
			}
		}

		if(!$gotLock) {
			throw new \Exception('Impossible de verrouiller : une tâche est peut être déjà en cours');
		}

		$dateDernier = file_get_contents($lockFile);
		$dateDuJour = date("Y-m-d");



		foreach ($this->configs as $kConfig => $config) {

			$this->logger->info($kConfig);

			if(!is_dir($this->cacheDir."/tmp_dumps/".$kConfig)) {
				mkdir($this->cacheDir."/tmp_dumps/".$kConfig, 0755, true);
			}
			if(!is_dir($this->backupdir."/".$kConfig."/sqls")) {
				mkdir($this->backupdir."/".$kConfig."/sqls", 0755, true);
			}


			// vérifie que le serveur de backup tourne
			$baseParams = [
				'CONTAINER' => $config['container'],
				'USER' => $config['user'],
			];
			if(!empty($config['passwordfile'])) {
				$baseParams['PASS'] = file_get_contents($config['passwordfile']);
			} else {
				$baseParams['PASS'] = $config['password'] ?? '';
			}

			// vérifie que l'esclave tourne
			$process = Process::fromShellCommandline('docker exec ${CONTAINER} mysql -u${USER} -p${PASS} -sN -e "SHOW STATUS LIKE \'Slave_Running\'"');
			$process->mustRun(null, $baseParams);
			$slaveStatus = $process->getOutput();
			if(!preg_match('#^Slave_running\s+ON$#', $slaveStatus)) {
				$this->logger->error(sprintf('Serveur %1$s : SLAVE NOT RUNNING', $kConfig));
				continue;
			} else {
				$this->logger->info(sprintf('Serveur %1$s : SLAVE OK', $kConfig));
			}

			// arrête le slave
			$process = Process::fromShellCommandline('docker exec ${CONTAINER} mysql -u${USER} -p${PASS} -e "STOP SLAVE"');
			$process->mustRun(null, $baseParams);

			// liste les bases à sauvegarder
			$process = Process::fromShellCommandline('docker exec ${CONTAINER} mysql -u${USER} -p${PASS} -sN -e "SHOW DATABASES"');
			$process->mustRun(null, $baseParams);
			$databases = explode("\n", $process->getOutput());

			// dump chaque base
			try {
				foreach ($databases as $database) {
					if ($database != "information_schema" && $database != "performance_schema") {
						$this->logger->info(sprintf('Dumping %1$s ...', $database));
						$params = array_merge($baseParams, [
							'DB' => $database,
							'OUTPUTPATH' => $this->cacheDir . "/tmp_dumps/" . $kConfig . "/" . $database . ".sql",
						]);
						$process = Process::fromShellCommandline('docker exec ${CONTAINER} mysqldump -u${USER} -p${PASS} --opt --databases ${DB} > ${OUTPUTPATH}');
						$process->setTimeout(3600);
						$process->mustRun(null, $params);

						$this->logger->info('OK');
					}
				}
			} catch (\Exception $e) {
				throw $e;
			} finally {
				// redémarre le slave dans tous les cas
				$process = Process::fromShellCommandline('docker exec ${CONTAINER} mysql -u${USER} -p${PASS} -e "START SLAVE"');
				$process->mustRun(null, $baseParams);
			}




			// rsync
			$this->logger->info('syncing...');

			$process = Process::fromShellCommandline('rdiff-backup ${FROM} ${TO}');
			$process->setTimeout(3600);
			$process->mustRun(null, [
				'FROM' => $this->cacheDir."/tmp_dumps/".$kConfig,
				'TO' => $this->backupdir."/".$kConfig."/sqls",
			]);

			// vide les backups de plus de 4 semaines
			$process = Process::fromShellCommandline('rdiff-backup --remove-older-than 4W --force ${TO}');
			$process->setTimeout(3600);
			$process->mustRun(null, [
				'TO' => $this->backupdir."/".$kConfig."/sqls",
			]);

			// supprime les fichiers temporaires
			$process = Process::fromShellCommandline('rm ${FROM}');
			$process->mustRun(null, [
				'FROM' => $this->cacheDir."/tmp_dumps/".$kConfig."/*.sql",
			]);

			$this->logger->info('done !');


		}

		if($dateDernier != $dateDuJour) {

			$mail = (new TemplatedEmail())
				->subject("Rapport quotidien backup SQL")
				->htmlTemplate('rapport_quotidien_backup_sql.html.twig')
				->context([
					'serveurs' => array_keys($this->configs)
				]);
			foreach($this->emailsNotif as $addr) {
				$mail->addTo($addr);
			}

			$this->mailer->send($mail);

			file_put_contents($lockFile, $dateDuJour);
		}



		return Command::SUCCESS;
	}
}
