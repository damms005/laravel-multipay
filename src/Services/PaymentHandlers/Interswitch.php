<?php

namespace Damms005\LaravelCashier\Services\PaymentHandlers;

use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Interswitch extends BasePaymentHandler implements PaymentHandlerInterface
{
    protected const NAME = "interswitch";
    protected const PRODUCT_ID = 1076;
    protected const PAY_ITEM_ID = 101;
    protected $transaction_successful = false;
    protected $user;
    protected $txn_ref;
    protected $amount_in_naira;
    protected $url_to_redirect_when_transaction_completed;
    protected $requery_url = "https://sandbox.interswitchng.com/collections/api/v1/gettransaction.json";
    protected $macKey = 'D3D1D05AFE42AD50818167EAC73C109168A0F108F32645C8B59E897FA930DA44F9230910DAC9E20641823799A107A02068F7BC0F4CC41D2952E249552255710F';

    public function __construct()
    {
    }

    public function build($user, $txn_ref, $amount_in_naira, $url_to_redirect_when_transaction_completed): Interswitch
    {
        $this->user = $user;
        $this->amount_in_naira = $amount_in_naira;
        $this->txn_ref = $txn_ref;
        $this->site_redirect_url = $url_to_redirect_when_transaction_completed;

        return $this;
    }

    public function persistToDatabaseAndShowAutosubmittedPaymentForm($getFormForTesting = true): \Illuminate\View\View
    {
        Payment::firstOrCreate([
            "user_id" => $this->user->id,
            "payment_processor" => self::NAME,
            "amount" => $this->amount_in_naira,
            "transaction_reference" => $this->txn_ref,
        ]);

        return view(
            'laravel-cashier::interswitch-form',
            [
                "hash" => $this->generateHashToSendInPaymentRequest(),
                "user" => $this->user,
                "amount" => $this->amount_in_naira * 100, //required amount to be posted in kobo
                "amount_in_naira" => $this->amount_in_naira,
                "txn_ref" => $this->txn_ref,
                "product_id" => self::PRODUCT_ID,
                "pay_item_id" => self::PAY_ITEM_ID,
                "site_redirect_url" => $this->site_redirect_url,
            ]
        );
    }

    public function renderAutoSubmittedPaymentForm(Payment $payment, $redirect_or_callback_url, bool $getFormForLiveApiNotTest = false)
    {
    }

    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
    {
        return null;
    }

    public function handleServerResponseOfUserPayment()
    {
        return $this->finalizePayment();
    }

    protected function finalizePayment()
    {
        $transaction_status_string = $this->getTransactionStatus();
        $transactionStatus = json_decode($transaction_status_string);

        if (json_last_error() === JSON_ERROR_NONE) {
            Log::debug($transaction_status_string);
            $payment = Payment::where('transaction_reference', $this->txn_ref)->firstOrFail();
            $payment->is_success = $transactionStatus->ResponseCode == '00' ? 1 : 0;
            $payment->processor_returned_amount = $transactionStatus->Amount;
            $payment->processor_returned_card_number = $transactionStatus->CardNumber ?? null;
            $payment->processor_transaction_reference = $transactionStatus->PaymentReference ?? null;
            $payment->processor_returned_response_code = $transactionStatus->ResponseCode;
            $payment->processor_returned_transaction_date = $transactionStatus->TransactionDate ?? null;
            $payment->processor_returned_response_description = $transactionStatus->ResponseDescription ?? null;

            $human_readable = $this->getHumanReadableTransactionResponse($payment);
            if (! empty($human_readable)) {
                if (empty($payment->processor_returned_response_description) || (trim($human_readable, ". \t\n\r\0\x0B") != trim($payment->processor_returned_response_description, ". \t\n\r\0\x0B"))) {
                    $payment->processor_returned_response_description = $transactionStatus->ResponseDescription;
                }
            }

            $payment->save();
            $this->user = $payment->user;
            $this->transaction_successful = true;
        } else {
            throw new \Exception("Transaction unsuccessful", 1);
        }
    }

    public function paymentIsSuccessful(Payment $payment): bool
    {
        return $this->transaction_successful;
    }

    public function generateHashToSendInPaymentRequest(): string
    {
        return hash('sha256', $this->txn_ref . self::PRODUCT_ID . self::PAY_ITEM_ID . "{$this->amount_in_naira}{$this->site_redirect_url}{$this->macKey}");
    }

    public function getTransactionStatus()
    {
        $parameters = [
            "amount" => $this->amount_in_naira * 100,
            "productid" => self::PRODUCT_ID,
            "transactionreference" => $this->txn_ref,
        ];

        $query = http_build_query($parameters);
        $url = "{$this->requery_url}?{$query}";

        //note the variables appended to the url as get values for these parameters
        $headers = [
            "GET /HTTP/1.1",
            "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.0.1) Gecko/2008070208 Firefox/3.0.1",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language: en-us,en;q=0.5",
            "Keep-Alive: 300",
            "Connection: keep-alive",
            "Hash: " . hash('sha256', self::PRODUCT_ID . "{$this->txn_ref}{$this->macKey}"),
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_POST, false);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new \Exception('Server error: ' . curl_error($ch), 1);
        }

        return $response;
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        $response_codes = $this->getResponseCodesArray();
        $human_readable = array_key_exists($payment->processor_returned_response_code, $response_codes) ? $response_codes[$payment->processor_returned_response_code] : "";

        return $human_readable;
    }

    public function reQuery(Payment $existingPayment): ?Payment
    {
        throw new \Exception("Method not yet implemented");
    }

    /**
     * @see \Damms005\LaravelCashier\Contracts\PaymentHandlerInterface::handlePaymentNotification
     */
    public function handlePaymentNotification(Request $request): Payment|bool|null
    {
        return null;
    }

    protected function getResponseCodesArray()
    {
        return [
            "00" => "Approved by Financial Institution",
            "01" => "Refer to Financial Institution",
            "02" => "Refer to Financial Institution, Special Condition",
            "03" => "Invalid Merchant",
            "04" => "Pick-up card",
            "05" => "Do Not Honor",
            "06" => "Error",
            "07" => "Pick-Up Card, Special Condition",
            "08" => "Honor with Identification",
            "09" => "Request in Progress",
            "10" => "Approved by Financial Institution",
            "11" => "Approved by Financial Institution",
            "12" => "Invalid Transaction",
            "13" => "Invalid Amount",
            "14" => "Invalid Card Number",
            "15" => "No Such Financial Institution",
            "16" => "Approved by Financial Institution, Update Track 3",
            "17" => "Customer Cancellation",
            "18" => "Customer Dispute",
            "19" => "Re-enter Transaction",
            "20" => "Invalid Response from Financial Institution",
            "21" => "No Action Taken by Financial Institution",
            "22" => "Suspected Malfunction",
            "23" => "Unacceptable Transaction Fee",
            "24" => "File Update not Supported",
            "25" => "Unable to Locate Record",
            "26" => "Duplicate Record",
            "27" => "File Update Field Edit Error",
            "28" => "File Update File Locked",
            "29" => "File Update Failed",
            "30" => "Format Error",
            "31" => "Bank Not Supported",
            "32" => "Completed Partially by Financial Institution",
            "33" => "Expired Card, Pick-Up",
            "34" => "Suspected Fraud, Pick-Up",
            "35" => "Contact Acquirer, Pick-Up",
            "36" => "Restricted Card, Pick-Up",
            "37" => "Call Acquirer Security, Pick-Up",
            "38" => "PIN Tries Exceeded, Pick-Up",
            "39" => "No Credit Account",
            "40" => "Function not Supported",
            "41" => "Lost Card, Pick-Up",
            "42" => "No Universal Account",
            "43" => "Stolen Card, Pick-Up, Stolen Card, Pick-Up",
            "44" => "No Investment Account",
            "45" => "Account Closed",
            "Z1(46)" => "Wrong login details on payment page attempting to login to QT",
            "51" => "Insufficient Funds",
            "52" => "No Check Account",
            "53" => "No Savings Account",
            "54" => "Expired Card",
            "55" => "Incorrect PIN",
            "56" => "No Card Record",
            "57" => "Transaction not Permitted to Cardholder",
            "58" => "Transaction not Permitted on Terminal",
            "59" => "Suspected Fraud",
            "60" => "Contact Acquirer",
            "61" => "Exceeds Withdrawal Limit",
            "62" => "Restricted Card",
            "63" => "Security Violation",
            "64" => "Original Amount Incorrect",
            "65" => "Exceeds withdrawal frequency",
            "66" => "Call Acquirer Security",
            "67" => "Hard Capture",
            "68" => "Response Received Too Late",
            "75" => "PIN tries exceeded",
            "76" => "Reserved for Future Postilion Use",
            "77" => "Intervene, Bank Approval Required",
            "78" => "Intervene, Bank Approval Required for Partial Amount",
            "90" => "Cut-off in Progress",
            "91" => "Issuer or Switch Inoperative",
            "Z1(92)" => "Routing Error.",
            "93" => "Violation of law",
            "94" => "Duplicate Transaction",
            "95" => "Reconcile Error",
            "96" => "System Malfunction",
            "98" => "Exceeds Cash Limit",
            "Z0" => "Transaction Not Completed",
            "Z4" => "Integration Error",
            "Z1" => "Transaction Error",
            "Z1(46)" => "Wrong login details on payment page attempting to login to QT",
            "Z1" => "(XM1)	Suspected Fraudulent Transaction",
            "Z5" => "Duplicate Transaction Reference",
            "Z6" => "Customer Cancellation",
            "Z25" => "Transaction not Found. Transaction you are querying does not exist on WebPAY",
            "Z30" => "Cannot retrieve risk profile",
            "Z61" => "Payment Requires Token.",
            "OTP" => "Cancellation",
            "Z62" => "Request to Generate Token is Successful",
            "Z63" => "Token Not Generated. Customer Not Registered on Token Platform",
            "Z64" => "Error Occurred. Could Not Generate Token",
            "Z65" => "Payment Requires Token Authorization",
            "Z66" => "Token Authorization Successful",
            "Z67" => "Token Authorization Not Successful. Incorrect Token Supplied",
            "Z68" => "Error Occurred. Could Not Authenticate Token",
            "Z69" => "Customer Cancellation Secure3D",
            "Z70" => "Cardinal Authentication Required",
            "Z71" => "Cardinal Lookup Successful",
            "Z72" => "Cardinal Lookup Failed / means the card didnt not exist on cardinal",
            "Z73" => "Cardinal Authenticate Successful",
            "Z74" => "Cardinal Authenticate Failed",
            "Z8" => "Invalid Card Details",
            "Z81" => "Bin has not been configured",
            "Z82" => "Merchant not configured for bin",
            "Z9" => "Cannot Connect to Passport Service",
            "Z15" => "Cannot Connect to Payphone Service",
            "Z16" => "Cannot Connect to Loyalty Service",
            "A3" => "Database Error",
            "A9" => "Incorrect Phone Number",
            "X04" => "Minimum Amount for Payment Item Not Met",
            "X03" => "Exceeds Maximum Amount Allowed",
            "Z1" => "(X10)	3d Secure Authenticate failed",
            "T0" => "Token Request Successful",
            "T1" => "Token Request Failed",
            "T2" => "Token Authentication Pending",
            "S0" => "TimeOut calling postilion service",
            "S1" => "Invalid response from Postilion Service",
            "XG0" => "Cannot Retrieve Collections Account",
            "XG1" => "Successfully Retrieved Collections Account",
            "XG2" => "Could not retrieve  collections account from key store",
            "PC1" => "Could not retrieve prepaid card number from key store",
            "XS1" => "Exceeded time period to completed transaction",
            "XNA" => "  No acquirer found for mutli acquired payable",
            "AE1" => "Auto enrollment balance enquiry failed",
            "AE2" => "Auto enrollment account number and phone number validation failed",
            "AE3" => "Auto enrollment cannot add card to Safe token",
            "AE4" => "Auto enrollment error occurred",
            "E10" => "Missing service identifier: You have not specify a service provider for this request, please specify a valid service identifier.",
            "E11" => "Missing transaction type: You have not specify a transaction type for this request, please specify a valid transaction type",
            "E12" => "Missing authentication credentials. Security token header might be missing.",
            "E18" => "The service provider is unreachable at the moment",
            "E19" => "An invalid response was received from remote host, please contact system administrator.",
            "E20" => "Request as timed out",
            "E21" => "An unknown error has occurred, please contact system administrator.",
            "E34" => "System busy",
            "E42" => "invalid auth data error. Pan or expiry date is empty.",
            "E43" => "PIN cannot be empty",
            "E48" => "Invalid OTP identifier code",
            "E49" => "Invalid AuthDataVersion code",
            "E54" => "Could not get response from HSM",
            "E56" => "The PAN contains an invalid character",
            "E57" => "The PIN contains an invalid character",
            "E60" => "Invalid merchant code",
            "E61" => "Invalid payable  code",
            "20021" => "No hash at all/no hash in requery",
            "20031" => "Invalid value for ProductId (in request or REQUERY) / amount must be supplied",
            "20050" => "Hash computation wrong/no hash in payment request",
        ];
    }
}
