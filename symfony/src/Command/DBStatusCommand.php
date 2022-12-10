<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

class DBStatusCommand extends Command
{
	protected static $defaultName = 'app:dbstatus';
	private $configs;
	private $tmpDir;
	private MailerInterface $mailer;
	private $emailsNotif;

	protected function configure(): void
	{
		$this
			->addOption('serveur', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Serveur à considérer');
	}

	public function __construct($configs, $tmpDir, MailerInterface $mailer, $emailsNotif)
	{
		parent::__construct();
		$this->configs = $configs;
		$this->tmpDir = $tmpDir;
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


		$hasError = false;
		$msgs = [];
		foreach ($this->configs as $kConfig => $config) {


			// vérifie que l'esclave tourne
			$params = [
				'USER' => $config['user'],
			];
			if (!empty($config['passwordfile'])) {
				$params['PASS'] = file_get_contents($config['passwordfile']);
			} else {
				$params['PASS'] = $config['password'];
			}

			$process = Process::fromShellCommandline('mysql -u${USER} -p${PASS} -sN -e "SHOW STATUS LIKE \'Slave_Running\'"');
			$process->mustRun(null, $params);
			$slaveStatus = $process->getOutput();
			if (!preg_match('#^Slave_running\s+ON$#', $slaveStatus)) {
				$msgs[] = sprintf('Serveur %1$s : SLAVE NOT RUNNING', $kConfig);
				$hasError = true;
			} else {

				// Seconds_Behind_Master

				$process = Process::fromShellCommandline('mysql -u${USER} -p${PASS} -sN -e "SHOW SLAVE STATUS\\G" | grep "Seconds_Behind_Master"');
				$process->mustRun(null, $params);
				$slaveDelay = $process->getOutput();

				if (!preg_match('#^\s*Seconds_Behind_Master\s*:\s*([0-9]+|NULL)#i', $slaveDelay, $matchesDelay)) {
					$msgs[] = sprintf('Serveur %1$s : COULD NOT PARSE Seconds_Behind_Master', $kConfig);
					$hasError = true;
				} else {
					if (strtolower($matchesDelay[1]) === "null" || $matchesDelay[1] <= 3600) {
						$msgs[] = sprintf('Serveur %1$s : OK (%2$s seconds behind master)', $kConfig, $matchesDelay[1]);
					} else {
						$msgs[] = sprintf('Serveur %1$s : LAGGING : %2$s seconds behind master', $kConfig, $matchesDelay[1]);
						$hasError = true;
					}
				}
			}
		}

		// on balance le mail
		$lockFile = $this->tmpDir."/health.lock";
		$gotLock = false;
		if($LOCK = fopen($lockFile, "a+")) {
			if(flock($LOCK, LOCK_EX | LOCK_NB)) {
				$gotLock = true;
			}
		}

		if(!$gotLock) {
			throw new \Exception('Impossible de verrouiller : une tâche est peut être déjà en cours');
		}



		$dateDernier = trim(file_get_contents($lockFile));
		$dateDuJour = date("Y-m-d");


		if($dateDernier != $dateDuJour || $hasError) {

			$mail = (new Email())
				->subject($hasError ? "Problème de réplication SQL" : "Réplication SQL OK")
				->text(implode("\n", $msgs));

			foreach($this->emailsNotif as $addr) {
				$mail->addTo($addr);
			}

			$this->mailer->send($mail);

			file_put_contents($lockFile, $dateDuJour);
		}

		return Command::SUCCESS;


	}
}
