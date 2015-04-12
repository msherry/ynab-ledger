## Introduction

This is just a hacky import script that reads YNAB exports (budget and
register) and produces ledger output to stdout.

## What it does

It transforms the budget into a set of virtual transactions at the
first of the month (or the day of the opening balance, for the first
month) balanced against the Assets account.  Categorised transactions
are exported with a non-balanced virtual posting to reduce the
category amount.  It guesses whether an account should be an asset or
a liability by its starting balance (anything positive denotes an
asset), or by the presence of “credit” in the account name.  Memos are
transformed into comments, either on the transaction or, for split
transactions, on each posting.

## Status

It mostly works and has served its purpose for me.  It doesn’t work so
well with off-budget accounts, although transferring into them seems
fine, if they have any income it will not be categorised correctly.

I don’t expect to develop this further, unless there is specific need.
I haven’t tested it with currencies other than the British Pound.  Let
me know if something doesn’t work (ideally with some test data) and
I’ll try to fix it.

If you want to fix it yourself, go ahead.  I’ll merge any pull request
that looks reasonable and works with my own data.

## Usage

```
composer install
./main.php convert /path/to/budget.csv /path/to/register.csv > new-ledger-file.dat
```

## License

The project is licensed under the BSD 3-Clause License.
