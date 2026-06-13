<?php
declare(strict_types=1);
/**
 * tests/_smoke_je_entry_type_whitelist.php
 *
 * MEDIUM [01a] — JournalEntryService::create() now whitelists entry_type against
 * the ENUM. An out-of-enum value used to reach the INSERT and throw a raw 1265
 * (data truncated) under STRICT → opaque HTTP 500. Now it throws a clear
 * RuntimeException("Invalid entry_type ...") before any write.
 *
 * PRE-FIX : invalid type → PDOException/1265 (no 'Invalid entry_type' message).
 * POST-FIX: RuntimeException with 'Invalid entry_type'; a valid 'manual' still posts.
 */
require_once dirname(__DIR__) . '/config/app.php';
use FleetForge\Accounting\JournalEntryService;
$fail=[]; $pass=0;
$P=static function($m)use(&$pass){$pass++;echo "  \033[32mPASS\033[0m — $m\n";};
$F=static function($m)use(&$fail){$fail[]=$m;echo "  \033[31mFAIL\033[0m — $m\n";};
$accts=db_select("SELECT id FROM acc_accounts WHERE is_active=1 AND is_header=0 ORDER BY id LIMIT 2",[]);
$d=(int)$accts[0]['id']; $c=(int)$accts[1]['id'];
$lines=[['account_id'=>$d,'debit'=>'10.00','credit'=>'0.00','description'=>'x'],
        ['account_id'=>$c,'debit'=>'0.00','credit'=>'10.00','description'=>'y']];
$jeId=null;
echo str_repeat('-',60)."\nMEDIUM [01a] JE entry_type whitelist\n".str_repeat('-',60)."\n";
try {
    // Case 1 — invalid entry_type rejected before any write.
    try {
        JournalEntryService::create(['entry_date'=>'2026-06-15','description'=>'je whitelist smoke',
            'entry_type'=>'totally_bogus_type','post_immediately'=>false], $lines, null);
        $F("1 invalid entry_type — create() did NOT throw");
    } catch (\Throwable $e) {
        if (stripos($e->getMessage(),'Invalid entry_type')!==false) $P("1 invalid entry_type — rejected: ".$e->getMessage());
        else $F("1 invalid entry_type — wrong error (pre-fix 1265): ".$e->getMessage());
    }
    // Case 2 — valid type still works (non-vacuous).
    try {
        $je=JournalEntryService::create(['entry_date'=>'2026-06-15','description'=>'je whitelist ok',
            'reference'=>'JE-WL-'.getmypid(),'entry_type'=>'manual','post_immediately'=>true], $lines, null);
        $jeId=(int)$je['id'];
        $P("2 valid 'manual' entry_type — posts (id={$jeId})");
    } catch (\Throwable $e) { $F("2 valid type failed: ".$e->getMessage()); }
} finally {
    if ($jeId){ db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id=?",[$jeId]);
                db_execute("DELETE FROM acc_journal_entries WHERE id=?",[$jeId]); }
    echo "  cleaned\n";
}
echo str_repeat('-',60)."\nJE ENTRY_TYPE — $pass passed, ".count($fail)." failed\n";
exit($fail?1:0);
