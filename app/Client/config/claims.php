<?php

/*
|--------------------------------------------------------------------------
| Claims
|--------------------------------------------------------------------------
|
| What a verified expense claim is booked to when Finance does not choose
| another account: the account number in the chart of accounts.
|
*/

return [

    // The expense account a sales expense claim is paid against by default (Freight Out).
    'expense_account' => '6300',

    // The expense account damaged goods are written off to (made in the chart when missing).
    'damaged_account' => '6600',

];
