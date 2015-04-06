<?php

namespace YnabLedger\Item;

class LedgerPosting {
    public $account = [];
    public $isVirtual = false;
    public $amount;
    public $currency;
    public $note;
}
