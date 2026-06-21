<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\AccountMembership;
use App\Entity\Instrument;
use App\Entity\PriceSnapshot;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\BrokerType;
use App\Enum\InstrumentType;
use App\Enum\MembershipRole;
use App\Enum\PriceSource;
use App\Enum\TransactionSource;
use App\Enum\TransactionType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds a realistic multi-currency portfolio mirroring a typical Google-Sheet
 * tracker: an owner with a personal account, a shared account with a second
 * user, instruments in EUR and USD, buys/partial-sells with fees across both
 * brokers, and a monthly price series so the performance graph has data.
 *
 * Dev login: any seeded user with password "password".
 */
class AppFixtures extends Fixture
{
    public const PASSWORD = 'password';

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        // --- Users -----------------------------------------------------------
        $sem = $this->makeUser('sem.schilder@team.blue', 'Sem Schilder');
        $partner = $this->makeUser('partner@example.com', 'Alex Partner');
        $manager->persist($sem);
        $manager->persist($partner);

        // --- Accounts + memberships -----------------------------------------
        $personal = new Account('Personal');
        $personal->addMembership(new AccountMembership($personal, $sem, MembershipRole::OWNER));
        $manager->persist($personal);

        $joint = new Account('Joint');
        $joint->addMembership(new AccountMembership($joint, $sem, MembershipRole::OWNER));
        $joint->addMembership(new AccountMembership($joint, $partner, MembershipRole::EDITOR));
        $manager->persist($joint);

        // --- Instruments (shared catalog) -----------------------------------
        $vwce = new Instrument('VWCE', 'Vanguard FTSE All-World UCITS ETF', InstrumentType::ETF, 'EUR', 'IE00BK5BQT80', 'XAMS');
        $asml = new Instrument('ASML', 'ASML Holding NV', InstrumentType::STOCK, 'EUR', 'NL0010273215', 'XAMS');
        $aapl = new Instrument('AAPL', 'Apple Inc.', InstrumentType::STOCK, 'USD', 'US0378331005', 'NASDAQ');
        $msft = new Instrument('MSFT', 'Microsoft Corp.', InstrumentType::STOCK, 'USD', 'US5949181045', 'NASDAQ');
        foreach ([$vwce, $asml, $aapl, $msft] as $instrument) {
            $manager->persist($instrument);
        }

        // --- Transactions on the personal account ---------------------------
        // EUR bucket: VWCE (two buys, one partial FIFO sell) + ASML.
        // USD bucket: AAPL (buy + partial sell) + MSFT, at IBKR.
        $txns = [
            [$vwce, BrokerType::DEGIRO, TransactionType::BUY, '2025-01-15', '10', '100.00', '2.00'],
            [$vwce, BrokerType::DEGIRO, TransactionType::BUY, '2025-03-10', '5', '110.00', '2.00'],
            [$vwce, BrokerType::DEGIRO, TransactionType::SELL, '2025-05-20', '6', '120.00', '2.00'],
            [$asml, BrokerType::DEGIRO, TransactionType::BUY, '2025-02-01', '3', '600.00', '3.00'],
            [$aapl, BrokerType::IBKR, TransactionType::BUY, '2025-01-20', '20', '180.00', '1.00'],
            [$aapl, BrokerType::IBKR, TransactionType::SELL, '2025-06-01', '5', '210.00', '1.00'],
            [$msft, BrokerType::IBKR, TransactionType::BUY, '2025-02-15', '10', '400.00', '1.00'],
        ];
        foreach ($txns as [$instrument, $broker, $type, $date, $qty, $price, $fee]) {
            $tx = new Transaction(
                $personal,
                $instrument,
                $broker,
                $type,
                new \DateTimeImmutable($date),
                $qty,
                $price,
                $instrument->getCurrency(),
                $fee,
            );
            $tx->setSource(TransactionSource::MANUAL);
            $manager->persist($tx);
        }

        // --- Price series (monthly closes; last point = current valuation) ---
        $series = [
            'VWCE' => ['2025-01-31' => '101', '2025-02-28' => '104', '2025-03-31' => '108', '2025-04-30' => '112', '2025-05-31' => '119', '2025-06-19' => '124'],
            'ASML' => ['2025-01-31' => '610', '2025-02-28' => '640', '2025-03-31' => '655', '2025-04-30' => '630', '2025-05-31' => '690', '2025-06-19' => '720'],
            'AAPL' => ['2025-01-31' => '185', '2025-02-28' => '190', '2025-03-31' => '196', '2025-04-30' => '205', '2025-05-31' => '208', '2025-06-19' => '215'],
            'MSFT' => ['2025-01-31' => '405', '2025-02-28' => '410', '2025-03-31' => '420', '2025-04-30' => '418', '2025-05-31' => '435', '2025-06-19' => '448'],
        ];
        $bySymbol = ['VWCE' => $vwce, 'ASML' => $asml, 'AAPL' => $aapl, 'MSFT' => $msft];
        foreach ($series as $symbol => $points) {
            foreach ($points as $date => $close) {
                $manager->persist(new PriceSnapshot(
                    $bySymbol[$symbol],
                    new \DateTimeImmutable($date),
                    $close,
                    PriceSource::MANUAL,
                ));
            }
        }

        $manager->flush();
    }

    private function makeUser(string $email, string $name): User
    {
        $user = new User($email, $name);
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));

        return $user;
    }
}
