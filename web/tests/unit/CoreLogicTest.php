<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HMD\Geo;
use HMD\StateMachine;
use HMD\Fmt;
use HMD\Validate;
use HMD\CsvImport;
use HMD\Ledger;
use HMD\ListQuery;
use HMD\Riders;
use HMD\Tips;

require_once __DIR__ . '/../../app/lib/autoload.php';

final class CoreLogicTest extends TestCase
{
    // ---------- Geo ----------
    public function testHaversineKnownDistance(): void
    {
        // Two points ~1.11 km apart along a meridian (0.01 deg latitude)
        $d = Geo::distanceM(28.4595, 77.0266, 28.4695, 77.0266);
        $this->assertEqualsWithDelta(1112, $d, 5);
    }

    public function testFilterDropsInaccurateDuplicateAndJumps(): void
    {
        $t = strtotime('2026-11-14 05:00:00');
        $pts = [
            ['lat' => 28.4595, 'lng' => 77.0266, 'accuracy' => 8,  'ts' => $t,       'seq' => 1],
            ['lat' => 28.4595, 'lng' => 77.0266, 'accuracy' => 8,  'ts' => $t,       'seq' => 1],   // duplicate seq
            ['lat' => 28.4605, 'lng' => 77.0266, 'accuracy' => 90, 'ts' => $t + 30,  'seq' => 2],   // inaccurate
            ['lat' => 28.4645, 'lng' => 77.0266, 'accuracy' => 10, 'ts' => $t + 60,  'seq' => 3],   // ~556 m / 60 s = 33 km/h ok
            ['lat' => 28.7000, 'lng' => 77.0266, 'accuracy' => 10, 'ts' => $t + 90,  'seq' => 4],   // 26 km in 30 s jump
            ['lat' => 28.4695, 'lng' => 77.0266, 'accuracy' => 10, 'ts' => $t + 120, 'seq' => 5],   // ~556 m / 60 s
        ];
        $kept = Geo::filterPoints($pts, 50, 120);
        $this->assertSame([1, 3, 5], array_column($kept, 'seq'));
        $km = Geo::pathKm($kept);
        $this->assertEqualsWithDelta(1.112, $km, 0.01);
    }

    public function testJumpConfirmedByNextPointIsKept(): void
    {
        $t = 1_000_000;
        $pts = [
            ['lat' => 28.0, 'lng' => 77.0, 'accuracy' => 5, 'ts' => $t, 'seq' => 1],
            ['lat' => 28.1, 'lng' => 77.0, 'accuracy' => 5, 'ts' => $t + 10, 'seq' => 2],   // jump (11 km in 10 s)
            ['lat' => 28.1001, 'lng' => 77.0, 'accuracy' => 5, 'ts' => $t + 20, 'seq' => 3], // confirms new position
        ];
        // A confirmed relocation (e.g. after a long gap the phone fixed position) is kept, but its segment is not counted as measured KM
        $kept = Geo::filterPoints($pts, 50, 120);
        $this->assertSame([1, 2, 3], array_column($kept, 'seq'));
        $this->assertTrue($kept[1]['gap'] ?? false);
        $this->assertEqualsWithDelta(0.011, Geo::pathKm($kept), 0.002);
    }

    public function testInsideGeofence(): void
    {
        $this->assertTrue(Geo::inside(28.4595, 77.0266, 28.4600, 77.0266, 150));
        $this->assertFalse(Geo::inside(28.4595, 77.0266, 28.4700, 77.0266, 150));
    }

    // ---------- State machine ----------
    public function testAllowedAndForbiddenTransitions(): void
    {
        $this->assertTrue(StateMachine::can('ready', 'assigned'));
        $this->assertTrue(StateMachine::can('assigned', 'loaded'));
        $this->assertTrue(StateMachine::can('loaded', 'out'));
        $this->assertTrue(StateMachine::can('out', 'en_route'));
        $this->assertTrue(StateMachine::can('en_route', 'arrived'));
        $this->assertTrue(StateMachine::can('arrived', 'delivered'));
        $this->assertTrue(StateMachine::can('arrived', 'not_delivered'));
        $this->assertTrue(StateMachine::can('not_delivered', 'rescheduled'));
        $this->assertTrue(StateMachine::can('rescheduled', 'ready'));
        $this->assertTrue(StateMachine::can('not_delivered', 'rto'));
        $this->assertTrue(StateMachine::can('rto', 'returned'));
        $this->assertTrue(StateMachine::can('assigned', 'ready'));      // unassign
        $this->assertTrue(StateMachine::can('ready', 'cancelled'));

        $this->assertFalse(StateMachine::can('ready', 'delivered'));
        $this->assertFalse(StateMachine::can('delivered', 'ready'));
        $this->assertFalse(StateMachine::can('out', 'cancelled'));
        $this->assertFalse(StateMachine::can('returned', 'out'));
        $this->assertFalse(StateMachine::can('nonsense', 'ready'));
    }

    public function testFailureOutcomeRouting(): void
    {
        // refused always goes to RTO; 3rd failure goes to RTO; otherwise reschedule
        $this->assertSame('rto', StateMachine::afterFailure('customer_refused', 1, 3));
        $this->assertSame('rescheduled', StateMachine::afterFailure('customer_unavailable', 1, 3));
        $this->assertSame('rto', StateMachine::afterFailure('customer_unavailable', 3, 3));
    }

    // ---------- Formatting ----------
    public function testIndianRupeeGrouping(): void
    {
        $this->assertSame('₹1,84,500.00', Fmt::inr(184500));
        $this->assertSame('₹999.50', Fmt::inr(999.5));
        $this->assertSame('-₹12,34,567.00', Fmt::inr(-1234567));
        $this->assertSame('₹0.00', Fmt::inr(0));
        $this->assertSame('₹3,940', Fmt::inr(3939.5, 0));
    }

    public function testUtcToIstDate(): void
    {
        $this->assertSame('14-11-2026 11:30', Fmt::dt('2026-11-14 06:00:00'));
        $this->assertSame('15-11-2026', Fmt::d('2026-11-14 19:00:00')); // 00:30 IST next day
        $this->assertSame('', Fmt::dt(null));
    }

    public function testMinutesToHuman(): void
    {
        $this->assertSame('8h 48m', Fmt::mins(528));
        $this->assertSame('19m', Fmt::mins(19));
        $this->assertSame('—', Fmt::mins(null));
    }

    // ---------- Validation ----------
    public function testMobileAndDateValidation(): void
    {
        $this->assertSame('9876543210', Validate::mobile('+91 98765-43210'));
        $this->assertSame('9876543210', Validate::mobile('09876543210'));
        $this->assertNull(Validate::mobile('12345'));
        $this->assertNull(Validate::mobile('5876543210')); // Indian mobiles start 6-9
        $this->assertTrue(Validate::date('2026-11-14'));
        $this->assertFalse(Validate::date('2026-02-30'));
        $this->assertFalse(Validate::date("2026-11-14' OR 1=1"));
        $this->assertSame('2026-11-14', Validate::dmyToYmd('14-11-2026'));
        $this->assertNull(Validate::dmyToYmd('31-02-2026'));
    }

    // ---------- CSV import ----------
    public function testCsvImportParsesAndReportsRowErrors(): void
    {
        $csv = "order_no,customer_name,customer_mobile,address,pincode,payment_type,cod_amount,order_value,items_count,priority,landmark,notes\n"
             . "HM1001,Ravi Sharma,9876543210,\"H.No 12, Sector 14, Gurgaon\",122001,cod,1250.50,1250.50,2,urgent,Near park,\n"
             . "HM1002,Asha,12345,Sector 22,122015,prepaid,0,499,1,normal,,\n"
             . "HM1003,Neha,9811111111,DLF Phase 3,122002,cod,,800,1,,,\n"
             . ",NoOrder,9811111112,Somewhere,122002,prepaid,0,100,1,,,\n";
        $res = CsvImport::parse($csv);
        $this->assertCount(4, $res['rows']);
        $this->assertSame([], $res['rows'][0]['errors']);
        $this->assertSame('cod', $res['rows'][0]['data']['payment_type']);
        $this->assertSame(1250.5, $res['rows'][0]['data']['cod_amount']);
        $this->assertSame('urgent', $res['rows'][0]['data']['priority']);
        $this->assertContains('Invalid mobile number', $res['rows'][1]['errors']);
        $this->assertSame(800.0, $res['rows'][2]['data']['cod_amount']); // COD defaults to order value
        $this->assertSame('normal', $res['rows'][2]['data']['priority']);
        $this->assertContains('Order number is required', $res['rows'][3]['errors']);
        $this->assertSame(2, $res['valid']);
    }

    public function testCsvImportRejectsMissingColumnsAndStripsBom(): void
    {
        $res = CsvImport::parse("\xEF\xBB\xBForder_no,customer_name\nA,B\n");
        $this->assertNotEmpty($res['fatal']);
        $this->assertStringContainsString('customer_mobile', $res['fatal']);
    }

    public function testCsvFormulaInjectionNeutralisedOnExport(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", CsvImport::safeCell('=HYPERLINK("x")'));
        $this->assertSame("'+91", CsvImport::safeCell('+91'));
        $this->assertSame('Ravi', CsvImport::safeCell('Ravi'));
    }

    // ---------- COD ledger ----------
    public function testCashInHand(): void
    {
        $txns = [
            ['type' => 'collect', 'mode' => 'cash', 'amount' => 1000],
            ['type' => 'collect', 'mode' => 'upi',  'amount' => 500],   // UPI never in rider's hand
            ['type' => 'collect', 'mode' => 'cash', 'amount' => 2000],
            ['type' => 'deposit', 'mode' => 'cash', 'amount' => 2500],
            ['type' => 'short',   'mode' => 'cash', 'amount' => 500],   // shortage stays as liability
        ];
        $this->assertSame(500.0, Ledger::cashInHand($txns));
        $this->assertSame(500.0, Ledger::shortPending($txns));
        $s = Ledger::settle(3000.0, 2500.0);
        $this->assertSame(-500.0, $s['difference']);
        $this->assertSame('short', $s['status']);
        $this->assertSame('settled', Ledger::settle(3000.0, 3000.0)['status']);
    }

    // ---------- List query whitelist ----------
    public function testSortWhitelistAndPaging(): void
    {
        $q = ListQuery::from(['sort' => 'd.created_at; DROP TABLE x', 'order' => 'sideways', 'page' => '-3', 'limit' => '9999'],
            ['d.created_at', 'd.order_no'], 'd.created_at');
        $this->assertSame('d.created_at', $q->sort);
        $this->assertSame('DESC', $q->order);
        $this->assertSame(1, $q->page);
        $this->assertSame(25, $q->limit);
        $q2 = ListQuery::from(['sort' => 'd.order_no', 'order' => 'asc', 'page' => '3', 'limit' => '50'], ['d.created_at', 'd.order_no'], 'd.created_at');
        $this->assertSame('d.order_no', $q2->sort);
        $this->assertSame('ASC', $q2->order);
        $this->assertSame(100, $q2->offset());
    }

    // ---------- Rider codes (v1.1) ----------
    public function testNextEmployeeCode(): void
    {
        $this->assertSame('DEL001', Riders::nextCode([]));
        $this->assertSame('DEL010', Riders::nextCode(['DEL001', 'DEL009', 'X12', 'del003']));
        $this->assertSame('DEL1000', Riders::nextCode(['DEL999']));
        $this->assertSame('DEL004', Riders::nextCode(['DEL003', 'DEL-OLD', 'DELIVERY']));
    }

    // ---------- Tooltips (v1.1) ----------
    public function testTipsAddedToLabelsColumnsKpisAndButtons(): void
    {
        $html = '<label>Status</label><th>Rider</th><th class="num"><a class="sort asc" href="?x=1">Attempts</a></th>'
            . '<div class="l">Riders on duty</div><button class="eva-btn primary"><i class="fa"></i> Filter</button>'
            . '<a class="eva-btn" href="?r=x" title="Mine">Export CSV</a><th>Unknown col</th><label>Nope label</label>';
        $out = Tips::apply($html);
        $this->assertSame(4, substr_count($out, 'class="tip"'));                      // label, 2 columns, KPI
        $this->assertStringContainsString('title="Apply the filters above"', $out);   // button got its tooltip
        $this->assertStringContainsString('title="Mine"', $out);                      // existing title kept
        $this->assertSame(1, substr_count($out, 'title='.chr(34).'Mine'));
        $this->assertStringContainsString('<th>Unknown col</th>', $out);              // unknown text untouched
    }
}
