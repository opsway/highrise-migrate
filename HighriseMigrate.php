<?php

require_once("HighriseAPI.class.php");

function errorHandler($errno, $errstr, $errfile, $errline) {
  if ( E_RECOVERABLE_ERROR === $errno ) {
    echo "'catched' catchable fatal error\n";
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
  }
  return false;
}
set_error_handler('errorHandler');

class HighriseMigrate {

	private $highrise1;
	private $highrise2;

	public function __construct() {
		$this->highrise1 = new HighriseAPI();
		$this->highrise1->debug = false;
		$this->highrise1->setAccount("YOUR account1");
		$this->highrise1->setToken("YOUR API TOKEN");

		$this->highrise2 = new HighriseAPI();
		$this->highrise2->debug = false;
		$this->highrise2->setAccount("YOUR account2");
		$this->highrise2->setToken("YOUR API TOKEN2");
	}


	function getMappedPerson($person) {
		$emails = $person->getEmailAddresses();
		echo "Looking for corresponding contact: " 	. $person->getFirstName() . " " . $person->getLastName() . "," . $person->getCompanyName() . (isset($emails[0]) ? "," . $emails[0] : "") . "\n";
	    
	    $searchTerms = array(
			 		  "firstname" => $person->getFirstName(), 
			 		  "lastname" => $person->getLastName()
			 		);		

		$preSearch = $this->highrise2->findPeopleBySearchTerm(implode(" ", $searchTerms));
		if (count($preSearch) == 0) {
			return false;
		}

		foreach ($preSearch as $mappedPerson) {
			$mappedEmails = $mappedPerson->getEmailAddresses();

			echo "Checking candidate contact: " . $mappedPerson->getFirstName() . " " . $mappedPerson->getLastName() . "," . $mappedPerson->getCompanyName() . (isset($mappedEmails[0]) ? "," . $mappedEmails[0] : "") . "\n";

			if ((!isset($emails[0]) || trim($emails[0]) == trim($mappedEmails[0]))
				&& trim($person->getCompanyName()) == trim($mappedPerson->getCompanyName())) {
				return $mappedPerson;
			}
		}
		return false;
	}

	function updateTags($person, $mappedPerson, $allTags) {
		$updated = false;
		if (count($mappedPerson->tags) == 0) {
			foreach($person->tags as $tag) {
				if (!isset($allTags[$tag->name])) {
					$newTag = new HighriseTag();
					$newTag->name = $tag->name;
					$mappedPerson->addTag($newTag);
					echo "Creating tag :" . $newTag->name . "\n";
					$allTags[$tag->name] = $newTag;
				} 
				$mappedPerson->addTag($tag->name);
				echo "Adding tag to person :" . $tag->name . "\n";
				$updated = true;
			}	
		} else {
			echo "Skipping tags, as already present\n";
			$updated = true;
		}
		if (!$updated) echo "No tags to add\n";
	}

	function updateNotes($person, $mappedPerson) {
		$updated = false;

		if (count($mappedPerson->getNotes()) == 0) {
			foreach ($person->getNotes() as $note) {
				$newNote = new HighriseNote($this->highrise2);
				$newNote->setBody(htmlspecialchars($note->getBody()));
				$newNote->setSubjectType("Party");
				$newNote->setSubjectId($mappedPerson->getId());
				$newNote->save();
			 	$mappedPerson->addNote($newNote);
			 	echo "Adding note ". "\n";	
			 	$updated = true;
			}		
		} else {
			echo "Skipping notes, as already present\n";
			$updated = true;
		}
		if (!$updated) echo "No notes to add\n";
	}


	function updateEmails($person, $mappedPerson) {
		$updated = false;
		if (count($mappedPerson->getEmails()) == 0) {
			foreach ($person->getEmails() as $email) {
				$new_email = new HighriseEmail($this->highrise2);
				$new_email->setTitle($email->getTitle());
				$new_email->setBody(htmlspecialchars($email->getBody()));
				$new_email->setSubjectType("Party");
				$new_email->setSubjectId($mappedPerson->getId());
				$new_email->save();
			 	$mappedPerson->addEmail($new_email);
			 	echo "Adding email ". $new_email->getTitle() . "\n";	
			 	$updated = true;
			}
		} else {
			echo "Skipping emails, as already present\n";
			$updated = true;
		} 
		if (!$updated) echo "No emails to add\n";
	}

	private function clonePerson($person) {
		echo "Cloning contact...\n";
		$newPerson = new HighrisePerson($this->highrise2);
		$newPerson->setFirstName($person->getFirstName());
		$newPerson->setLastName($person->getLastName());
		$newPerson->setTitle($person->getTitle());
		$newPerson->setCompanyName($person->getCompanyName());

		$emails = $person->getEmailAddresses();
		if (isset($emails[0])) {
			$newEmail = new HighriseEmailAddress(null, $emails[0]);
			$newPerson->addEmailAddress($newEmail);
		}

		$messagers = $person->getInstantMessengers();
		if (isset($messagers[0])) {
			$newMessager = new HighriseInstantMessenger();
			$newMessager->setProtocol($messagers[0]->getProtocol());
			$newMessager->setAddress($messagers[0]->getAddress());			
			$newPerson->addInstantMessenger($newMessager);
		}

		$newPerson->save();
	}

	public function run() {
		$allTags = $this->highrise2->findAllTags();

		$i = 0;
		$people = $this->highrise1->findAllPeople();
		foreach($people as $person) {
			echo "Processing contact " . ++$i . " of " . count($people);
			echo "\n----------------------------- \n";
			try {
				$mappedPerson = $this->getMappedPerson($person);
				if (!$mappedPerson) {
					$this->clonePerson($person);
					$mappedPerson = $this->getMappedPerson($person);
				}

				$mappedPersonEmails = $mappedPerson->getEmailAddresses();
				echo "Contact found, updating...\n";

				$this->updateTags($person, $mappedPerson, $allTags);
				$this->updateNotes($person, $mappedPerson);
				$this->updateEmails($person, $mappedPerson);

				$mappedPerson->save();		
			} catch (Exception $e) {
				echo "Error during saving person encountered\n";
			}
		}
	}
}

$migrate = new HighriseMigrate();
try {
	$migrate->run();	
} catch (Exception $e) {
	echo $e->getTraceAsString();
}
