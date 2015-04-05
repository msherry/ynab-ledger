<?php

namespace YnabLedger\Item;

class RegisterTransaction {
    public $line;
    public $account;
    public $date;
    public $payee;
    public $category;
    public $memo;
    public $out;
    public $in;
    public $cleared = false;
}
