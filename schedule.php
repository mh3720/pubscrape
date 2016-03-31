#!/usr/bin/php
<?php

// Property tax auction listings for Dallas County
// Q: When are the notices posted?
// A: All Notice of Sales must be posted 21 days prior to the sale
//    (21 days before the first Tuesday of each month).
// http://www.dallascounty.org/department/countyclerk/foreclosures.php
$deadline = strtotime("first tuesday of next month -21 days");

// If today is after the deadline, calculate deadline for next month.
if (intval(date('Ymd')) > $deadline) {
    $deadline = strtotime('first tuesday of +2 months -21 days');
}

echo 'Dallas County listing deadline for next auction = ' .
    date('Y-m-d, l', $deadline) . "\n";

