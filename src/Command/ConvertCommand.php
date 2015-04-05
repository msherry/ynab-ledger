<?php

namespace YnabLedger\Command;

use Generator;
use SplFileObject;
use LimitIterator;
use SeekableIterator;
use ArrayIterator;
use DateTimeImmutable;
use NumberFormatter;
use CLIFramework\Command;
use YnabLedger\Item\RegisterTransaction;
use YnabLedger\Item\BudgetTransaction;

function convertDate ($currencySymbol) {
    $dateFormat;
    return function ($dateString) use ($currencySymbol, &$dateFormat) {
        if ($dateFormat === null) {
            if (is_numeric(substr($dateString, -4))) {
                if (ord($currencySymbol) == ord('£')) {
                    $dateFormat = 'd/m/Y';
                } else {
                    $dateFormat = 'm/d/Y';
                }
            } else {
                $dateFormat = false;
            }
        }
        if ($dateFormat === false) {
            return new DateTimeImmutable($dateString);
        } else {
            return DateTimeImmutable::createFromFormat(
                $dateFormat,
                $dateString
            );
        }
    };
}

function readBudget (SplFileObject $budgetFile) {
    $budgetFile->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
    $file = new LimitIterator($budgetFile, 1);
    $fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);

    foreach ($file as $row) {
        $txn = new BudgetTransaction;
        $txn->date = DateTimeImmutable::createFromFormat('d F Y', '1 ' . $row[0])->setTime(0, 0, 0);
        $txn->category = explode(':', $row[1], 2);
        $txn->in = $fmt->parseCurrency($row[4], $curr);
        $txn->out = $fmt->parseCurrency($row[5], $curr);
        $txn->balance = $row[6];
        if (!in_array($row[2], $txn->category, true)) {
            array_unshift($txn->category, $row[2]);
        }
        yield $txn;
    }
}

function readRegister (SplFileObject $registerFile, $locale) {
    $registerFile->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

    $iterator = new LimitIterator($registerFile, 1);
    $iterator->rewind();
    $convertDate = convertDate($iterator->current()[9]);

    $fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);

    $txns = [];
    foreach ($iterator as $i => $row) {
        // "Account","Flag","Check Number","Date","Payee","Category","Master Category","Sub Category","Memo","Outflow","Inflow","Cleared","Running Balance"
        $txn = new RegisterTransaction();
        $txn->account = $row[0];
        $txn->date = $convertDate($row[3])->setTime(0, 0, 0);
        $txn->payee = $row[4];
        $txn->category = explode(':', $row[5]);
        if (!in_array($row[6], $txn->category, true)) {
            array_unshift($txn->category, $row[6]);
        }
        $txn->memo = $row[8];
        $txn->out = $fmt->parseCurrency($row[9], $curr);
        $txn->in = $fmt->parseCurrency($row[10], $curr);
        $txn->cleared = ($row[11] !== 'U');
        $txn->line = $i;
        $txns[] = $txn;
    }

    // Sort by date, then promote income and fall back on line in file
    usort($txns,
          function ($a, $b) use ($convertDate) {
              return strcmp($a->date->format('U'), $b->date->format('U'))
                  ?: ($b->in < 0.01
                      ? 0 : $a->line - $b->line);
          }
    );
    foreach ($txns as $txn) yield $txn;
}

function multiRead (Generator $budgetFile, Generator $registerFile) {
    $budgetFile->rewind();
    foreach ($registerFile as $rTxn) {
        if ($rTxn->payee !== 'Starting Balance' &&
            $rTxn->category[1] !== 'Available this month') {
            if ($budgetFile->valid()) {
                $bTxn = $budgetFile->current();
                $bDate = $bTxn->date;
                if ($bTxn->date < $rTxn->date) {
                    sleep(2);
                    do {
                        yield $bTxn;

                        $budgetFile->next();
                        $bTxn = $budgetFile->current();
                    } while ($bTxn->date == $bDate);
                }
            }
        }
        yield $rTxn;
    }
}

class ConvertCommand extends Command {
    public function brief () {
        return 'Convert budget & register exports';
    }

    public function arguments ($args) {
        $args->add('budget')
            ->desc('Budget export file')
            ->isa('file')
            ->glob('*-Budget.csv');

        $args->add('register')
            ->desc('Register export file')
            ->isa('file')
            ->glob('*-Register.csv');
    }

    public function execute ($budgetFile, $registerFile) {
        $locale = 'en_GB';
        $budget = readBudget(new SplFileObject($budgetFile));
        $register = readRegister(new SplFileObject($registerFile), $locale);
        foreach (multiRead($budget, $register) as $txn) {
            printf("%-8s %-10s\t%-30s %s\n",
                   $txn instanceof RegisterTransaction ? 'Register' : 'Budget',
                   $txn->date->format('Y-m-d'),
                   implode(':', $txn->category),
                   isset($txn->payee) ? $txn->payee : ''
            );
        }
    }
}
