<?php

namespace AppBundle\Command;

use AppBundle\Entity\Comment;
use AppBundle\Entity\Decklist;
use AppBundle\Entity\Decklistslot;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seeds the database with fake users and published decklists (including
 * votes, favorites and comments) so a development instance has data to
 * browse and an API worth testing. Never use on a production database.
 *
 * Card data must be imported first (app:import:std).
 */
class SeedDevDataCommand extends ContainerAwareCommand
{
	private $adjectives = ['Relentless', 'Cosmic', 'Amazing', 'Spectacular', 'Uncanny', 'Mighty', 'Savage', 'Astonishing', 'Incredible', 'Fearless'];
	private $nouns = ['Justice', 'Aggression', 'Protection', 'Leadership', 'Assault', 'Defense', 'Gambit', 'Onslaught', 'Vanguard', 'Response'];
	private $comments = [
		'Love this list, works great against Rhino.',
		'Have you considered swapping the allies for more economy?',
		'Took this to a 4-player game, held up surprisingly well.',
		'This deck struggles against steady villains imo.',
		'Nice writeup, thanks for sharing!',
	];

	protected function configure()
	{
		$this
			->setName('app:seed:dev')
			->setDescription('Seed the database with fake users and decklists (votes, favorites, comments) for development')
			->addOption('users', null, InputOption::VALUE_REQUIRED, 'Number of users to create or reuse', 5)
			->addOption('decklists', null, InputOption::VALUE_REQUIRED, 'Number of decklists to create', 15);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$nbUsers = max(1, (int) $input->getOption('users'));
		$nbDecklists = max(1, (int) $input->getOption('decklists'));

		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->getContainer()->get('doctrine')->getManager();

		$heroes = $em->createQuery(
			"SELECT c FROM AppBundle:Card c JOIN c.type t WHERE t.code = 'hero'"
		)->getResult();
		if (!count($heroes)) {
			$output->writeln('<error>No hero cards found. Import card data first (app:import:std).</error>');

			return 1;
		}

		$playerCards = $em->createQuery(
			"SELECT c FROM AppBundle:Card c JOIN c.type t JOIN c.faction f"
			. " WHERE t.code IN ('ally','event','upgrade','support','resource')"
			. " AND f.code IN ('aggression','justice','leadership','protection','basic')"
		)->getResult();

		$users = $this->getOrCreateUsers($nbUsers, $output);

		for ($i = 0; $i < $nbDecklists; $i++) {
			$decklist = $this->createDecklist($em, $users, $heroes, $playerCards);
			$em->persist($decklist);
			$output->writeln(sprintf('Created decklist "%s" (%s)', $decklist->getName(), $decklist->getCharacter()->getName()));
		}

		$em->flush();
		$output->writeln(sprintf('<info>Done: %d users, %d decklists.</info>', count($users), $nbDecklists));

		return 0;
	}

	private function getOrCreateUsers($nbUsers, OutputInterface $output)
	{
		$userManager = $this->getContainer()->get('fos_user.user_manager');

		$users = [];
		for ($i = 1; $i <= $nbUsers; $i++) {
			$username = "seeduser$i";
			$user = $userManager->findUserByUsername($username);
			if (!$user) {
				$user = $userManager->createUser();
				$user->setUsername($username);
				$user->setEmail("$username@example.com");
				$user->setPlainPassword('password');
				$user->setEnabled(true);
				$userManager->updateUser($user);
				$output->writeln("Created user $username (password: password)");
			}
			$users[] = $user;
		}

		return $users;
	}

	private function createDecklist($em, array $users, array $heroes, array $playerCards)
	{
		$hero = $heroes[mt_rand(0, count($heroes) - 1)];
		$user = $users[mt_rand(0, count($users) - 1)];

		$name = $this->adjectives[mt_rand(0, count($this->adjectives) - 1)]
			. ' ' . $this->nouns[mt_rand(0, count($this->nouns) - 1)]
			. ' ' . $hero->getName();

		// the hero's signature cards (player cards from the hero's own set)
		$signatureCards = $em->createQuery(
			"SELECT c FROM AppBundle:Card c JOIN c.faction f"
			. " WHERE c.card_set = :cardset AND f.code = 'hero' AND c.type <> :heroType"
		)->setParameters(['cardset' => $hero->getCardSet(), 'heroType' => $hero->getType()])->getResult();

		$content = [];
		foreach ($signatureCards as $card) {
			$content[$card->getCode()] = $card->getQuantity() ?: 1;
		}
		shuffle($playerCards);
		foreach (array_slice($playerCards, 0, 20) as $card) {
			$content[$card->getCode()] = mt_rand(1, min(2, $card->getDeckLimit() ?: 2));
		}

		$decklist = new Decklist();
		$decklist->setName($name);
		$decklist->setVersion('1.0');
		$decklist->setNameCanonical($this->slugify($name) . '-1.0');
		$decklist->setDescriptionMd('Seeded development decklist.');
		$decklist->setDescriptionHtml('<p>Seeded development decklist.</p>');
		$decklist->setDateCreation(new \DateTime(sprintf('-%d days', mt_rand(0, 90))));
		$decklist->setDateUpdate(new \DateTime());
		$decklist->setSignature(md5(json_encode($content)));
		$decklist->setCharacter($hero);
		$decklist->setLastPack($hero->getPack());
		$decklist->setUser($user);

		foreach ($content as $code => $quantity) {
			$card = $em->getRepository('AppBundle:Card')->findOneBy(['code' => $code]);
			$slot = new Decklistslot();
			$slot->setQuantity($quantity);
			$slot->setIgnoreDeckLimit(false);
			$slot->setCard($card);
			$slot->setDecklist($decklist);
			$decklist->getSlots()->add($slot);
		}

		// votes and favorites are real join-table rows plus the denormalized counters
		shuffle($users);
		$voters = array_slice($users, 0, mt_rand(0, count($users)));
		foreach ($voters as $voter) {
			$decklist->addVote($voter);
		}
		$favoriters = array_slice($users, 0, mt_rand(0, 2));
		foreach ($favoriters as $favoriter) {
			$decklist->addFavorite($favoriter);
		}

		$nbComments = mt_rand(0, 3);
		for ($i = 0; $i < $nbComments; $i++) {
			$comment = new Comment();
			$comment->setText($this->comments[mt_rand(0, count($this->comments) - 1)]);
			$comment->setUser($users[mt_rand(0, count($users) - 1)]);
			$comment->setDecklist($decklist);
			$comment->setIsHidden(false);
			$em->persist($comment);
		}

		$decklist->setNbVotes(count($voters));
		$decklist->setNbfavorites(count($favoriters));
		$decklist->setNbcomments($nbComments);

		return $decklist;
	}

	private function slugify($text)
	{
		$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));

		return $slug ?: 'deck';
	}
}
