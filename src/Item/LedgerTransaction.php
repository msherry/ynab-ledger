<?php

namespace YnabLedger\Item;

class LedgerTransaction {
    public $date;
    public $state;
    public $payee;
    public $note;
    public $postings = [];
}
