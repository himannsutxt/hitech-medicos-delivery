// English / Hindi strings. Riders switch language on the login or profile screen.
import 'package:shared_preferences/shared_preferences.dart';

class L {
  static bool hindi = false;

  static Future<void> load() async {
    final p = await SharedPreferences.getInstance();
    hindi = p.getBool('hindi') ?? false;
  }

  static Future<void> set(bool hi) async {
    hindi = hi;
    final p = await SharedPreferences.getInstance();
    await p.setBool('hindi', hi);
  }

  static const Map<String, List<String>> _t = {
    'app': ['Hitech Medicos Delivery', 'हाईटेक मेडिकोस डिलीवरी'],
    'server_title': ['Connect to your store', 'अपने स्टोर से जुड़ें'],
    'server_help': ['Enter the server address your manager gave you.', 'मैनेजर द्वारा दिया गया सर्वर पता डालें।'],
    'server_hint': ['e.g. delivery.hitechmedicos.in', 'जैसे delivery.hitechmedicos.in'],
    'server_bad': ['This address is not valid. It must be an https address.', 'पता सही नहीं है। https पता होना चाहिए।'],
    'server_unreachable': ['Could not reach the server. Check the address and internet.', 'सर्वर से कनेक्ट नहीं हो पाया। पता और इंटरनेट जांचें।'],
    'continue': ['Continue', 'आगे बढ़ें'],
    'login': ['Log in', 'लॉग इन'],
    'mobile': ['Mobile number', 'मोबाइल नंबर'],
    'pin': ['4-digit PIN', '4 अंकों का पिन'],
    'change_server': ['Change server', 'सर्वर बदलें'],
    'home': ['Home', 'होम'],
    'deliveries': ['Deliveries', 'डिलीवरी'],
    'scan': ['Scan', 'स्कैन'],
    'cod': ['COD', 'COD'],
    'profile': ['Profile', 'प्रोफ़ाइल'],
    'on_duty': ['On duty', 'ड्यूटी पर'],
    'off_duty': ['Off duty', 'ड्यूटी बंद'],
    'start_duty': ['START DUTY', 'ड्यूटी शुरू करें'],
    'end_duty': ['END DUTY', 'ड्यूटी खत्म करें'],
    'start_route': ['START ROUTE', 'रूट शुरू करें'],
    'scan_parcels': ['SCAN PARCELS', 'पार्सल स्कैन करें'],
    'left_to_scan': ['left to scan', 'स्कैन बाकी'],
    'deposit_cod': ['DEPOSIT COD', 'COD जमा करें'],
    'assigned': ['Assigned', 'असाइन'],
    'delivered': ['Delivered', 'डिलीवर'],
    'pending': ['Pending', 'बाकी'],
    'failed': ['Not delivered', 'डिलीवर नहीं'],
    'km_today': ['KM today', 'आज के KM'],
    'cash_in_hand': ['Cash in hand', 'हाथ में नकद'],
    'current_order': ['Current order', 'मौजूदा ऑर्डर'],
    'next_order': ['Next order', 'अगला ऑर्डर'],
    'all_done': ['All deliveries done. Go back to the store, hand over undelivered parcels and deposit COD.', 'सभी डिलीवरी पूरी। स्टोर लौटें, बचे पार्सल और COD जमा करें।'],
    'no_orders': ['No orders assigned yet. Pull down to refresh.', 'अभी कोई ऑर्डर नहीं। रिफ्रेश के लिए नीचे खींचें।'],
    'navigate': ['NAVIGATE', 'रास्ता'],
    'call': ['CALL', 'कॉल'],
    'start_delivery': ['START DELIVERY', 'डिलीवरी शुरू'],
    'arrived': ['ARRIVED', 'पहुँच गया'],
    'mark_delivered': ['DELIVERED', 'डिलीवर किया'],
    'cant_deliver': ["CAN'T DELIVER", 'डिलीवर नहीं हुआ'],
    'confirm': ['Confirm', 'पुष्टि करें'],
    'cancel': ['Cancel', 'रद्द करें'],
    'take_photo': ['Take photo', 'फोटो लें'],
    'retake': ['Retake', 'दोबारा लें'],
    'photo_needed': ['Take a photo as proof.', 'प्रूफ के लिए फोटो लें।'],
    'otp': ['Customer OTP', 'ग्राहक OTP'],
    'otp_help': ['Ask the customer for the 4-digit OTP sent to their phone.', 'ग्राहक से उनके फ़ोन पर आया 4 अंकों का OTP पूछें।'],
    'resend_otp': ['Resend OTP', 'OTP दोबारा भेजें'],
    'collect': ['Collect', 'वसूलें'],
    'cash': ['Cash', 'नकद'],
    'upi': ['UPI', 'UPI'],
    'utr': ['UPI transaction ID (UTR)', 'UPI ट्रांज़ैक्शन ID (UTR)'],
    'received_by': ['Received by (name)', 'किसने लिया (नाम)'],
    'reason': ['Why could you not deliver?', 'डिलीवरी क्यों नहीं हुई?'],
    'remark': ['What happened? (optional)', 'क्या हुआ? (वैकल्पिक)'],
    'remark_required': ['Write what happened (at least 10 letters).', 'क्या हुआ लिखें (कम से कम 10 अक्षर)।'],
    'new_date': ['Date the customer wants', 'ग्राहक की चुनी तारीख'],
    'saved_offline': ['Saved. Will sync when internet is back.', 'सेव हुआ। इंटरनेट आने पर भेजा जाएगा।'],
    'offline': ['Offline', 'ऑफ़लाइन'],
    'waiting_sync': ['waiting to sync', 'भेजना बाकी'],
    'sync_now': ['Sync now', 'अभी भेजें'],
    'gps_off': ['GPS is off. Turn on location to continue.', 'GPS बंद है। लोकेशन चालू करें।'],
    'perm_bg': ['Allow location "All the time" so tracking works when the phone is locked.', 'फ़ोन लॉक होने पर भी ट्रैकिंग के लिए लोकेशन "हमेशा" की अनुमति दें।'],
    'fix': ['Fix', 'ठीक करें'],
    'battery_tip': ['Turn off battery saver for this app so tracking is not stopped.', 'इस ऐप के लिए बैटरी सेवर बंद करें।'],
    'selfie': ['Take a selfie to start duty', 'ड्यूटी शुरू करने के लिए सेल्फी लें'],
    'skip': ['Skip', 'छोड़ें'],
    'logout': ['Log out', 'लॉग आउट'],
    'logout_on_duty': ['End duty before logging out.', 'लॉग आउट से पहले ड्यूटी खत्म करें।'],
    'language': ['हिंदी में दिखाएँ', 'Show in English'],
    'support': ['Call support', 'सपोर्ट को कॉल करें'],
    'sync_problems': ['Not accepted by server', 'सर्वर ने स्वीकार नहीं किया'],
    'scan_hint': ['Point the camera at the parcel barcode', 'कैमरा पार्सल बारकोड पर रखें'],
    'type_code': ['Type order number', 'ऑर्डर नंबर लिखें'],
    'deposit_help': ['Hand the cash to accounts. They confirm the amount on the website.', 'नकद अकाउंट्स को दें। वे वेबसाइट पर पुष्टि करेंगे।'],
    'waiting_accounts': ['Waiting for accounts to confirm', 'अकाउंट्स की पुष्टि का इंतज़ार'],
    'today_collections': ["Today's collections", 'आज की वसूली'],
    'refresh': ['Refresh', 'रिफ्रेश'],
    'sure_end': ['End duty now? Tracking will stop.', 'ड्यूटी खत्म करें? ट्रैकिंग बंद हो जाएगी।'],
    'cold': ['Keep cold', 'ठंडा रखें'],
    'urgent': ['Urgent', 'तुरंत'],
    'priority': ['Priority', 'प्राथमिकता'],
    'items': ['items', 'आइटम'],
    'prepaid': ['Prepaid', 'प्रीपेड'],
    'attempt': ['Attempt', 'प्रयास'],
    'notes': ['Notes', 'नोट'],
    'distance': ['away', 'दूर'],
    'mark_arrived_manual': ['Mark arrived manually?', 'मैन्युअली पहुँचा मार्क करें?'],
    'manual_note': ['Your location will be recorded with this.', 'इसके साथ आपकी लोकेशन दर्ज होगी।'],
  };

  static String t(String k) {
    final v = _t[k];
    if (v == null) return k;
    return hindi ? v[1] : v[0];
  }
}

String tr(String k) => L.t(k);
