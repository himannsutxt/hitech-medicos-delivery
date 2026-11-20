<?php
declare(strict_types=1);
namespace HMD;

/**
 * Tooltip text for every filter label, column, KPI tile and button, keyed by the visible label.
 * Ui::page() adds the (i) icon / title automatically so no page can ship without them.
 */
final class Tips
{
    public const LABELS = [
        // filters & form labels
        'Search' => 'Type part of a name, number or code. Matches anywhere in the text.',
        'Status' => 'Show only records in this state.',
        'Rider' => 'Show only records for this rider.',
        'Payment' => 'Prepaid = already paid online. COD = rider collects cash or UPI at the door.',
        'Priority' => 'Urgent and priority orders show first in the rider app.',
        'Created from' => 'Orders created on or after this date (IST).',
        'From' => 'Start date (IST), included.',
        'To' => 'End date (IST), included.',
        'Live status' => 'What the rider is doing right now, based on the app.',
        'Type' => 'Kind of entry.',
        'Mode' => 'Cash or UPI.',
        'Severity' => 'Critical and high need checking first.',
        'Who' => 'Staff, rider or automatic system action.',
        'Day' => 'Pick a date to see that day\'s route and numbers.',
        'Sort' => 'Order of the list.',
        // columns
        'Order' => 'Your order or bill number. Click to open the full timeline.',
        'Customer' => 'Customer name, mobile and pincode.',
        'Attempts' => 'How many times a rider tried to deliver this order.',
        'Created' => 'When the order was added (IST).',
        'Delivered' => 'When it was delivered (IST).',
        'Mobile' => 'Login number for the rider app.',
        'Vehicle' => 'Vehicle type and number.',
        'Live' => 'Rider\'s current state from the app.',
        'Cash in hand' => 'COD cash the rider has collected and not yet deposited.',
        'Short pending' => 'Shortage recorded at deposit that is not yet recovered or written off.',
        'Licence expiry' => 'Red = expired, amber = expires within 30 days.',
        'Phone' => 'Registered = the rider\'s phone is locked to their account. Reset it from the rider page if they change phones.',
        'Last seen' => 'Last time the rider\'s app contacted the server.',
        'Current order' => 'Order the rider is delivering right now.',
        'Done / pending' => 'Delivered today / still to deliver.',
        'KM today' => 'Distance travelled on today\'s duty, from GPS.',
        'Battery' => 'Phone battery at the last update.',
        'Last GPS' => 'How long ago the last location arrived.',
        'Date' => 'Date (IST).',
        'Amount' => 'Amount in rupees.',
        'Reference' => 'UPI transaction ID (UTR) or note.',
        'Requested' => 'When the rider tapped Deposit COD.',
        'Expected cash' => 'Cash the rider should hand over, from the ledger.',
        'Confirm' => 'Count the cash, enter the amount received and confirm.',
        'Adjust shortage' => 'Record money recovered from the rider, or write off a shortage (needs permission).',
        'Duty' => 'Duty start–end time and total duty hours.',
        'Not delivered' => 'Failed, refused or rescheduled attempts.',
        'Success' => 'Delivered ÷ all attempts closed that day.',
        'KM' => 'Measured GPS distance plus estimated distance across GPS gaps.',
        'Avg delivery' => 'Average time from Start Delivery to Delivered.',
        'COD' => 'COD collected (cash + UPI).',
        'Closed' => 'When the attempt finished (IST).',
        'Result' => 'Outcome of the attempt and the reason given.',
        'Travel' => 'Time from Start Delivery to arriving at the customer.',
        'At customer' => 'Time spent at the customer\'s door.',
        'Total' => 'Start Delivery to Delivered / Not delivered.',
        'From address' => 'How far the phone was from the customer\'s pin when the rider closed the attempt.',
        'Distance from address' => 'How far the phone was from the customer\'s pin when the rider closed the attempt.',
        'Proof' => 'Photo or OTP captured at the door.',
        'When' => 'Date and time (IST).',
        'Details' => 'What was detected.',
        'Action' => 'What was done.',
        'Record' => 'Which record was changed.',
        'Change' => 'Values before and after the change.',
        'IP' => 'Network address the action came from.',
        'Line' => 'Row number in your file.',
        'Address' => 'Delivery address.',
        'Check' => 'OK rows will be imported; rows with problems are skipped.',
        'Name' => 'Full name.',
        'Email' => 'Used to log in.',
        'Role' => 'Decides which pages and actions this user can use.',
        'Last login' => 'Last time this user logged in (IST).',
        'Job' => 'Automatic task run by the cron script.',
        'Last run' => 'When this job last ran (IST).',
        'Next run' => 'When it runs next.',
        'Failures' => 'How many times it has failed so far.',
        'Value' => 'Current value on this server.',
        'Time' => 'Time (IST).',
        // KPI tiles
        'Riders on duty' => 'Riders who have tapped START DUTY and not ended it.',
        'Ready to assign' => 'Orders waiting for a rider. Assign them from Orders.',
        'Out for delivery' => 'Orders currently with riders on the road.',
        'Delivered today' => 'Orders delivered since midnight (IST).',
        'Failed today' => 'Attempts today that ended not delivered (failed, refused or rescheduled).',
        'Success rate' => 'Delivered ÷ (delivered + not delivered) today.',
        'Travelled today' => 'Total KM by all riders today.',
        'COD collected today' => 'Cash and UPI collected today.',
        'Cash with riders' => 'COD cash collected but not yet deposited, across all riders.',
        'Duty start' => 'When the rider started duty that day.',
        'Duty time' => 'Total hours on duty that day.',
        'Delivered / assigned' => 'Deliveries completed out of those assigned that day.',
        'Travelled' => 'KM on duty that day.',
        'Cash in hand now' => 'COD cash the rider holds right now.',
        'Cash collected' => 'Cash collected in the selected dates.',
        'UPI collected' => 'UPI payments in the selected dates.',
        'Deposited' => 'Cash handed to accounts in the selected dates.',
        'COD collected' => 'Cash + UPI collected in the selected dates.',
    ];

    public const BUTTONS = [
        'Filter' => 'Apply the filters above',
        'Show' => 'Apply the filters above',
        'Reset' => 'Clear all filters and sorting',
        'Export CSV' => 'Download exactly what the filters show, as a CSV file for Excel',
        'Import CSV' => 'Add many orders at once from an Excel/CSV file, with a preview first',
        'Add order' => 'Add a single delivery order',
        'Add rider' => 'Add a new rider: name, mobile and PIN',
        'Assign' => 'Give the selected orders to the chosen rider',
        'Unassign' => 'Take the rider off the selected orders; they go back to Ready',
        'Export selected' => 'Download only the ticked rows as CSV',
        'Cancel' => 'Cancel without saving',
        'Save view' => 'Save the current filters and sorting as a one-click view',
        'Edit' => 'Change the details',
        'Log out' => 'End your session on this device',
        'Preview' => 'Check the file and show what will be imported, before saving anything',
        'Start again' => 'Discard this preview and upload another file',
        'Receive parcel' => 'Record that this parcel is back in the store',
        'Confirm received' => 'Confirm the cash you counted. A difference needs a remark and is recorded as a shortage',
        'Recovered' => 'Record money the rider paid back against a shortage',
        'Write off' => 'Remove a shortage that will not be recovered (Super Admin)',
        'Mark resolved' => 'Close the selected exceptions with your note',
        'Run now' => 'Run this job immediately instead of waiting for cron',
        'Send test message' => 'Send a test SMS/WhatsApp to the number entered',
        'Download settings backup' => 'Download all settings and store locations as a file',
        'Restore' => 'Load settings from a backup file',
        'Save store' => 'Save the store name, address, radius and map pin',
        'Add another store' => 'Add a second store or warehouse location',
        'Save user' => 'Save this staff user',
        'Add user' => 'Create a login for an office staff member',
        'New role' => 'Create a role with its own permissions',
        'Save role' => 'Save the permissions for this role',
        'Set new PIN' => 'Change the rider\'s app PIN. They are logged out and must use the new PIN',
        'Reset phone' => 'Allow the rider to log in on a new phone',
        'Disable' => 'Stop this rider from logging in (records are kept)',
        'Enable' => 'Let this rider log in again',
        'Delete' => 'Hide the rider from lists. History is kept and they can be restored',
        'Restore rider' => 'Bring a deleted rider back',
        'Back to orders' => 'Return to the orders list',
        'Back to riders' => 'Return to the riders list',
        'Cancel order' => 'Mark this order cancelled (history is kept)',
        'Save order' => 'Save this order',
        'Save and add another' => 'Save and open a blank form for the next order',
        'Clear' => 'Remove the map pin',
        'Download template' => 'Download a sample CSV with the right column names',
        'Install' => 'Create the database tables and your admin login',
        'Log in' => 'Log in to the admin panel',
        'Go to login' => 'Open the login page',
        'Help' => 'Setup guide and common questions',
    ];

    /** Adds (i) tooltips to labels, column headers and KPI captions, and title text to buttons. */
    public static function apply(string $html): string
    {
        $tip = static fn(string $t) => isset(self::LABELS[$t]) ? Ui::tip(self::LABELS[$t]) : '';
        // <label>Text</label> and <label class="req">Text</label>
        $html = preg_replace_callback('#<label( class="[^"]*")?>([^<>]{1,60})</label>#u', static fn($m) => '<label' . $m[1] . '>' . $m[2] . $tip(trim($m[2])) . '</label>', $html) ?? $html;
        // plain and sortable column headers
        $html = preg_replace_callback('#(<th(?: class="[^"]*")?>)([^<>]{1,40})(</th>)#u', static fn($m) => $m[1] . $m[2] . $tip(trim($m[2])) . $m[3], $html) ?? $html;
        $html = preg_replace_callback('#(<a class="sort[^"]*" href="[^"]*">)([^<>]{1,40})(</a>)(</th>)#u', static fn($m) => $m[1] . $m[2] . $m[3] . $tip(trim($m[2])) . $m[4], $html) ?? $html;
        // KPI captions and timeline-free <dt> labels
        $html = preg_replace_callback('#(<div class="l">)([^<>]{1,40})(</div>)#u', static fn($m) => $m[1] . $m[2] . $tip(trim($m[2])) . $m[3], $html) ?? $html;
        // buttons and button-links without a title
        $html = preg_replace_callback('#<(button|a)(\s[^>]*?class="eva-btn[^"]*"[^>]*)>(.*?)</\1>#su', static function ($m) {
            if (str_contains($m[2], 'title=')) {
                return $m[0];
            }
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
            $text = preg_replace('/\s+/', ' ', $text) ?? $text;
            $key = preg_replace('/\s*\(\d+\)$|\s*·.*$/', '', $text) ?? $text;
            $t = self::BUTTONS[$key] ?? ($text !== '' ? $text : null);
            if ($t === null) {
                return $m[0];
            }
            return '<' . $m[1] . $m[2] . ' title="' . Ui::e($t) . '">' . $m[3] . '</' . $m[1] . '>';
        }, $html) ?? $html;
        return $html;
    }
}
