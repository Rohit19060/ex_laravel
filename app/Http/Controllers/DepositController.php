<?php

namespace App\Http\Controllers;

use App\Helpers\AppHelpers;
use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class DepositController extends Controller
{
    // Stripe Payment Gateway
    public function stripePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cust_id' => 'required|exists:customers,id',
            'amount' => 'required|min:1',
        ]);
        if ($validator->fails()) {
            return AppHelpers::sendResponse(false, $validator->errors()->first(), Response::HTTP_BAD_REQUEST);
        }

        $grand_total = $request->amount * 100;

        if ($grand_total < 100) {
            return AppHelpers::sendResponse(null, 'Minimum Deposit limit is ₹100', Response::HTTP_BAD_REQUEST);
        }
        if ($grand_total > 1000) {
            return AppHelpers::sendResponse(null, 'Maximum Deposit limit is ₹1000', Response::HTTP_BAD_REQUEST);
        }

        $mode = "PROD";
        if ($mode == "TEST") {
            $secret = env('STRIPE_TEST_KEY');
        } else {
            $secret =  env('STRIPE_LIVE_KEY');
        }
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.stripe.com/v1/payment_intents",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'amount=' . $grand_total . '&currency=INR',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $secret,
                'Content-Type: application/x-www-form-urlencoded'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        // Check for errors
        if ($response === false) {
            return AppHelpers::sendResponse(false, 'Error occurred', Response::HTTP_BAD_REQUEST);
        }
        // Check for curl errors
        if (curl_errno($curl)) {
            return AppHelpers::sendResponse(false, 'Error occurred', Response::HTTP_BAD_REQUEST);
        }
        $response = json_decode($response, true);

        Deposit::create([
            'cust_id' => $request->cust_id,
            'amount' => $request->amount,
            'payment_id' => $response['id'],
            'payment_status' => $response['status'],
            'payment_mode' => 'Stripe',
            'payment_response' => json_encode($response),
        ]);

        return AppHelpers::sendResponse(["intent" => $response, "mode" => $mode], 'Payment Intent', Response::HTTP_OK);
    }

    public function stripeDeposit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cust_id' => 'required|exists:customers,id',
            'amount' => 'required',
        ]);
        if ($validator->fails()) {
            return AppHelpers::sendResponse(false, $validator->errors()->first(), Response::HTTP_BAD_REQUEST);
        }

        // update Payment Status

        return AppHelpers::sendResponse(true, 'Rs ' . $request->coupon_id . ' Added Successfully', Response::HTTP_OK);
    }


    // Paytm Payment Gateway
    public function paytmDeposit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cust_id' => 'required',
            'email' => 'required|email',
            'amount'      => 'required|numeric|min:100',
        ]);
        if ($validator->fails()) {
            return AppHelpers::sendResponse(false, $validator->errors()->first(), Response::HTTP_BAD_REQUEST);
        }
        $amount = $request->amount;
        $orderID =  time();
        $payTMParams = array();

        $key = "";
        $mid = "";
        $website = "DEFAULT";
        $isStaging = false;

        if ($isStaging) {
            $callbackUrl = "https://securegw-stage.paytm.in/theia/paytmCallback?ORDER_ID=$orderID";
            $url = "https://securegw-stage.paytm.in/theia/api/v1/initiateTransaction?mid=$mid&orderId=$orderID";
        } else {
            $callbackUrl = "https://securegw.paytm.in/theia/paytmCallback?ORDER_ID=$orderID";
            $url = "https://securegw.paytm.in/theia/api/v1/initiateTransaction?mid=$mid&orderId=$orderID";
        }

        $payTMParams["body"] = array(
            "requestType" => "Payment",
            "mid" => $mid,
            "websiteName" => $website,
            "orderId" => $orderID,
            "callbackUrl" => $callbackUrl,
            "txnAmount" => array(
                "value" => $amount,
                "currency" => "INR",
            ),
            "userInfo" => array(
                "custId" => $request->cust_id,
                "email" =>  $request->email,
            ),
        );
        $checksum = \PaytmChecksum::generateSignature(json_encode($payTMParams["body"], JSON_UNESCAPED_SLASHES), $key);
        $payTMParams["head"] = array(
            "signature" => $checksum
        );
        $post_data = json_encode($payTMParams, JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
        $response = curl_exec($ch);
        $var = json_decode($response, true);

        if ($var["body"]["resultInfo"]["resultStatus"] == "S") {
            $data['success'] =  true;
            $data['txnToken'] =  $var["body"]["txnToken"];
            $data['orderId'] =  $orderID;
            $data['amount'] =  $amount;
            $data['message'] =  "Payment Initiated";
            $data['mid'] =  $mid;
            $data['callbackUrl'] =  $callbackUrl;
            $data['isStaging'] =  $isStaging;
            Deposit::create([
                'cust_id' => $request->cust_id,
                'amount' => $request->amount,
                'source' => 'PAYTM',
                'status' => 'PENDING'
            ]);
            return AppHelpers::sendResponse($data, 'Wallet Add Payment Request.', Response::HTTP_OK);
        } else {
            $data['success'] =  false;
            $data['message'] =  "PayTm Server Error! Please Try Again";
            return AppHelpers::sendResponse($data, 'Error occurred', Response::HTTP_BAD_REQUEST);
        }
    }


    public function paytmVerifySign(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cust_id'          => 'required',
            'order_id'          => 'required',
            'transaction_id'      => 'required',
            'payment_response' => 'required'
        ]);

        if ($validator->fails()) {
            return  AppHelpers::sendResponse(false, $validator->errors()->first(), Response::HTTP_BAD_REQUEST);
        }

        $order_id = $request->order_id;
        $transaction_id   = $request->transaction_id;
        $payment_response = $request->payment_response;
        $custID = $request->customerID;
        $grand_total = Deposit::where(['order_id' => $order_id, 'customer_id' => $custID])->first();
        if ($grand_total && $grand_total->status == '0') {
            $grand_total->status = '1';
            $grand_total->transaction_id = $transaction_id;
            $grand_total->payment_response = $payment_response;
            $grand_total->save();
            return AppHelpers::sendResponse(null, 'Payment is Successfully added to Your Wallet', Response::HTTP_OK);
        } else {
            return AppHelpers::sendResponse(null, 'Transaction Failed.', Response::HTTP_OK);
        }
    }
}
