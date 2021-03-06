<?php

namespace YnabLedger\Command;

use Generator;
use SplFileObject;
use LimitIterator;
use SeekableIterator;
use ArrayIterator;
use DateTimeImmutable;
use NumberFormatter;
use UnexpectedValueException;
use CLIFramework\Command;
use YnabLedger\Item\RegisterTransaction;
use YnabLedger\Item\BudgetTransaction;
use YnabLedger\Item\LedgerTransaction;
use YnabLedger\Item\LedgerPosting;

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

function convertCleared ($val) {
    switch ($val) {
        case 'U': case null:
            return '';
        case 'R': case 'C':
            return '*';
        default:
            throw new UnexpectedValueException(
                sprintf("Found a cleared flag with value '%s', but I don't know what it means", $val)
            );
    }
}

function getAccount ($name, $txn) {
    static $accounts = [];
    if (!isset($accounts[$name])) {
        if ($txn->in >= 0.01 || stripos($name, 'credit') === false) {
            $accounts[$name] = ['Assets', $name];
        } else {
            $accounts[$name] = ['Liabilities', $name ?: end($txn->category)];
        }
    }
    return $accounts[$name];
}

function createCategoryPosting (RegisterTransaction $txn) {
    $category = new LedgerPosting;
    $category->isVirtual = true;
    $category->currency = $txn->currency;
    $category->account = array_merge(['Funds'], $txn->category);
    $category->amount = $txn->in - $txn->out;
    return $category;
}

function toLedger (Generator $transactions) {
    $transactions->rewind();
    $accounts = [];

    while ($transactions->valid()) {
        $inc = true;
        try {
            $txn = $transactions->current();
            $lTxn = new LedgerTransaction;
            $lTxn->date = $txn->date;
            $lTxn->state = convertCleared($txn->cleared);
            $lTxn->payee = $txn->payee;
            $lTxn->note = $txn->memo;

            if ($txn instanceof BudgetTransaction) {
                $startDate = $txn->date;
                $lTxn->isVirtual = true;
                do {
                    if ($txn->category[0] != 'Pre-YNAB Debt') {
                        $posting = new LedgerPosting;
                        $posting->currency = $txn->currency;
                        $posting->amount = $txn->in;
                        $posting->account = array_merge(['Funds'], $txn->category);
                        if ($posting->amount !== 0.00) {
                            $lTxn->postings[] = $posting;
                        }
                    }

                    $transactions->next();
                    $txn = $transactions->current();
                    $inc = false;
                } while (
                    $txn instanceof BudgetTransaction &&
                    $txn->date == $startDate
                );

                $posting = new LedgerPosting;
                $posting->account = ['Assets'];
                $lTxn->postings[] = $posting;
            } elseif ($txn->payee == 'Starting Balance') {
                $startDate = $txn->date;
                do {
                    $posting = new LedgerPosting;
                    $posting->currency = $txn->currency;
                    $posting->account = getAccount($txn->account, $txn);
                    $posting->amount = $txn->in - $txn->out;
                    $lTxn->postings[] = $posting;

                    $transactions->next();
                    $txn = $transactions->current();
                    $inc = false;
                } while (
                    $txn->payee == 'Starting Balance' &&
                    $txn->date == $startDate
                );

                $posting = new LedgerPosting;
                $posting->account = ['Equity', 'Opening Balances'];
                $lTxn->postings[] = $posting;
            } elseif (substr($txn->payee, 0, 8) === 'Transfer') {
                if ($txn->out <= 0.00) {
                    goto next;
                }
                $target = new LedgerPosting;
                $target->account = getAccount(explode(' : ', $txn->payee)[1], $txn);
                $target->currency = $txn->currency;
                $target->amount = $txn->out - $txn->in;
                $lTxn->postings[] = $target;

                $source = new LedgerPosting;
                $source->account = getAccount($txn->account, $txn);
                $source->amount = $txn->in - $txn->out;
                $source->currency = $txn->currency;
                $lTxn->postings[] = $source;

                $lTxn->payee = 'Transfer';
            } elseif (substr($lTxn->note, 0, 6) == '(Split') {
                $payee = $txn->payee;
                $startDate = $txn->date;
                $lTxn->note = '';

                $source = new LedgerPosting;
                $source->account = getAccount($txn->account, $txn);
                $source->currency = $txn->currency;

                do {
                    $target = new LedgerPosting;
                    if (empty($txn->category)) {
                        $target->account = getAccount(explode(' : ', explode(' / ', $txn->payee, 2)[1])[1], $txn);
                    } elseif ($txn->category[0] == 'Income') {
                        $target->account = $txn->category;
                    } else {
                        $target->account = array_merge(['Expenses'], $txn->category);
                    }
                    $target->currency = $txn->currency;
                    $target->amount = $txn->out - $txn->in;
                    $source->amount += $txn->in - $txn->out;
                    sscanf($txn->memo, "(Split %d/%d) %[^\r]", $i, $k, $target->note);
                    $lTxn->postings[] = $target;
                    if ($target->account[0] == "Expenses") {
                        $lTxn->postings[] = createCategoryPosting($txn);
                    }

                    $transactions->next();
                    $txn = $transactions->current();
                    $inc = false;
                } while (
                    $txn->date == $startDate &&
                    $txn->payee === $payee &&
                    substr($txn->memo, 0, 6) == '(Split'
                );
                $lTxn->postings[] = $source;

            } else {
                $target = new LedgerPosting;
                if ($txn->category[0] == 'Income') {
                    $target->account = $txn->category;
                } else {
                    $target->account = array_merge(['Expenses'], $txn->category);
                }
                $target->currency = $txn->currency;
                $target->amount = $txn->out - $txn->in;
                $lTxn->postings[] = $target;

                $source = new LedgerPosting;
                $source->account = getAccount($txn->account, $txn);
                $source->amount = $txn->in - $txn->out;
                $source->currenty = $txn->currency;
                $lTxn->postings[] = $source;

                if ($target->account[0] == "Expenses") {
                    $lTxn->postings[] = createCategoryPosting($txn);
                }
            }
            yield $lTxn;
            next:
            if ($inc) {
                $transactions->next();
            }
            if (!$transactions->valid()) {
                return;
            }
        } catch (UnexpectedValueException $e) {
            var_dump($txn);
            throw $e;
        }
    }
}

function readBudget (SplFileObject $budgetFile) {
    $budgetFile->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
    $file = new LimitIterator($budgetFile, 1);
    $fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);

    foreach ($file as $row) {
        $txn = new BudgetTransaction;
        $txn->date = DateTimeImmutable::createFromFormat('d F Y', '1 ' . $row[0])->setTime(0, 0, 0);
        $txn->category = explode(':', $row[1], 2);
        $txn->in = $fmt->parseCurrency($row[4], $txn->currency);
        $txn->out = $fmt->parseCurrency($row[5], $txn->currency);
        $txn->balance = $row[6];
        // if (!in_array($row[2], $txn->category, true)) {
        //     array_unshift($txn->category, $row[2]);
        // }
        yield $txn;
    }
}

function readRegister (SplFileObject $registerFile, NumberFormatter $fmt) {
    $registerFile->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

    $iterator = new LimitIterator($registerFile, 1);
    $iterator->rewind();
    $convertDate = convertDate($iterator->current()[9]);

    $txns = [];
    foreach ($iterator as $i => $row) {
        // "Account","Flag","Check Number","Date","Payee","Category","Master Category","Sub Category","Memo","Outflow","Inflow","Cleared","Running Balance"
        $txn = new RegisterTransaction();
        $txn->account = $row[0];
        $txn->date = $convertDate($row[3])->setTime(0, 0, 0);
        $txn->payee = $row[4];
        $txn->category = array_filter(array_map('trim', explode(':', $row[5])));
        // if (!in_array($row[6], $txn->category, true)) {
        //     array_unshift($txn->category, $row[6]);
        // }
        $txn->memo = trim($row[8]);
        $txn->out = $fmt->parseCurrency($row[9], $txn->currency);
        $txn->in = $fmt->parseCurrency($row[10], $txn->currency);
        $txn->cleared = $row[11];
        $txn->line = $i;
        $txns[] = $txn;
    }

    // Sort by date, promote transfers, group splits, promote income and fall back on line in file
    usort($txns,
          function ($a, $b) use ($convertDate) {
              return strcmp($a->date->format('U'), $b->date->format('U'))
                  ?: ($a->out < 0.01 ? -1
                      : (strpos($b->payee, 'Transfer : ') !== false ? 1
                         : ((in_array('Split', [substr($a->memo, 1, 5), substr($b->memo, 1, 5)]) ?
                             strcmp($a->memo, $b->memo)
                             : $a->line - $b->line))));
          }
    );
    foreach ($txns as $txn) yield $txn;
}

function multiRead (SplFileObject $budgetFile, SplFileObject $registerFile, NumberFormatter $fmt) {
    $budget = readBudget($budgetFile);
    $register = readRegister($registerFile, $fmt);
    $budget->rewind();
    $openDate;
    foreach ($register as $rTxn) {
        if ($openDate !== null) {
            if ($rTxn->payee !== 'Starting Balance' &&
                $rTxn->category[1] !== 'Available this month') {
                if ($budget->valid()) {
                    $bTxn = $budget->current();
                    $bDate = $bTxn->date;
                    if ($bTxn->date < $rTxn->date) {
                        do {
                            $bTxn->date = $openDate;
                            yield $bTxn;

                            $budget->next();
                            $bTxn = $budget->current();
                        } while ($bTxn->date == $bDate);
                    }
                }
            }
        } elseif ($rTxn->payee === 'Starting Balance') {
            $openDate = $rTxn->date;
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
        $fmt = new NumberFormatter('en_GB', NumberFormatter::CURRENCY);
        $export = toLedger(multiRead(new SplFileObject($budgetFile), new SplFileObject($registerFile), $fmt));

        foreach ($export as $txn) {
            echo "{$txn->date->format('Y-m-d')} "
                , $txn->state ? "$txn->state " : ""
                , $txn->payee
                , !empty($txn->note) ? "  ; $txn->note" : ""
                , PHP_EOL;
            foreach ($txn->postings as $posting) {
                printf(
                    "  %-40s",
                    ($txn->isVirtual ? "[" : ($posting->isVirtual ? "(" : ""))
                    . implode(':', $posting->account)
                    . ($txn->isVirtual ? "]" : ($posting->isVirtual ? ")" : ""))
                );
                if ($posting->currency !== null) {
                    printf("  %10s", $fmt->formatCurrency($posting->amount, $posting->currency));
                }
                echo !empty($posting->note) ? "  ; $posting->note" : "", PHP_EOL;
            }
            echo PHP_EOL;
        }
    }
}
