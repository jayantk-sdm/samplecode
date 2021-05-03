<?php

namespace App\Http\Controllers\Customer\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCard;
use App\Models\The1Code;
use App\Models\PointIssuer;
use App\Models\Transaction;
use App\Models\Setting;
use App\Models\Notification;
use App\Models\The1CodeTier;
use App\Models\CustomerThe1Transaction;
use Validator;
use App\Helpers\UserNotification as UserNotification;
use App\Helpers\TransactionHelper;
use Illuminate\Support\Facades\Crypt;
use App\Helpers\BalanceHelper;
use DB;

class SuscoServicesController extends Controller {

    /**
     *  headers : {"content-type":"Application/json","accept":"Application/json","device-token":"1235656","device-type":"ios","app-version":"1.0","access-token":"","Accept-Language":"en","merchant-id":""}
     */
    public function __construct(Request $request) {
        parent::__construct();
        parent::set_headers($request);
    }

    public function getErrors($errors = null) {
        $error_msg = '';
        if (!empty($errors)) {
            foreach ($errors as $key1 => $error) {
                foreach ($error as $key2 => $text) {
                    $error_msg = $text;
                    break;
                }
            }
        }
        return $error_msg;
    }

    /* 	
     * function name 	: verifySuscoMember 
     * input 		: {"phone_number":"0999999999"}
     * Method           : POST
     */

    public function verifySuscoMember(Request $request) {

        $status = 'FAIL';
        $responseCode = '400';
        $message = '';
        $result = '';
        $app_version = $this->app_version;
        $device_type = $this->device_type;
        $customer_id = $this->customer_id;
        $language = $this->language;
        $system_ip = $_SERVER['REMOTE_ADDR'];
        $data = $request->all();

        $rules = ['phone_number' => 'required'];
        $validationMesssages = ['phone_number.required' => trans('api_messages.failure.phone_number_error')];
        $validator = \Validator::make($request->all(), $rules, $validationMesssages);
        if ($validator->fails()) {
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);
        }

        if ($validator->passes()) {

            $phone_number = $data['phone_number'];
            $susco_auth_token = Setting::where('meta_key', 'susco_auth_token')->value('meta_value');
            $headers = array(
                "authorization: Bearer " . $susco_auth_token
            );
            $data_string = "mobile=".$phone_number;
            
            $logRequestData = [
                'customer_id' => $customer_id,
                'mobile' => $phone_number,
                'ip_address' => $system_ip
            ];

            generate_logs_by_folder_structure('Susco Request initiate', $logRequestData, $headers, 'Susco Request initiate', 'Susco');
            try {

                $susco_url = Setting::where('meta_key', 'susco_url')->value('meta_value');
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $susco_url.'/MemberVerify');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS,$data_string);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $output = curl_exec($ch);
                curl_close($ch);
                $curlResult = json_decode($output);
                generate_logs_by_folder_structure('Susco Request Curl Request - ' . $customer_id, $logRequestData, $curlResult, 'Susco Request  Curl Request', 'Susco');

                if ($curlResult) {
                    if (isset($curlResult->success) && $curlResult->success == true) {
                        $result = [
                            'first_name' => $curlResult->first_name,
                            'last_name' => $curlResult->last_name,
                            'susco_total_amount'=>$curlResult->total_amount
                        ];
                        $status = 'OK';
                        $message = $curlResult->msg;
                        $responseCode = '200';
                    } else {
                        $message = $curlResult->msg;
                    }
                }
            } catch (\Exception $e) {
                \Log::info($customer_id . 'susco-renderResponse function error ' . $e->getMessage() . ' at line no. ' . $e->getCode() . ' in file ' . $e->getFile());
            }
        }

        $response['status'] = $status;
        $response['message'] = $message;
        $response['data'] = $result;
        return response()->json($response, $responseCode);
    }

    /* function name    : suscoConvertOut
     * input            : {"point_issuer_id":"1","phone_number":"0999999999",'amount':'10.00'}
     * Method           : POST
     * Description      : Create convert out transaction
     */

    public function suscoConvertOut(Request $request) {
        $status = 'FAIL';
        $message = $transData = [];
        $responseCode = '400';
        $language = $this->language;
        $customer_id = $this->customer_id;
        $system_ip = $_SERVER['REMOTE_ADDR'];
        $data = $request->all();

        $rules = ['point_issuer_id' => 'required', 'phone_number' => 'required', 'amount' => 'required'];
        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);
        }
        if ($validator->passes()) {
            
            $point_issuer = PointIssuer::where('id', $data['point_issuer_id'])->where('partner_type', '11')->where('status', '1')->first();
            if ($point_issuer) {

                $amount = $data['amount'];
                $customerInfo = Customer::where('id', $customer_id)->where('phone_number',$data['phone_number'])->select('phone_number', 'available_points')->first();
                $customerPoints = $customerInfo['available_points'];
                if ($amount <= $customerPoints) {
                    $susco_auth_token = Setting::where('meta_key', 'susco_auth_token')->value('meta_value');
                    $headers = array(
                        "authorization: Bearer " . $susco_auth_token
                    );
                   
                    $transactionID = round(microtime(true) * 1000) + $customer_id;
                    $data_string = "mobile=".$data['phone_number'].'&transaction_id='.$transactionID."&Point_transfer=".$data['amount'];

                    generate_logs_by_folder_structure('1-Susco Transfer Request initiate', $data_string, $headers, 'Susco Transfer Request initiate', 'Susco');
                     
                    try {
                        $susco_url = Setting::where('meta_key', 'susco_url')->value('meta_value');
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $susco_url.'/XCashTransferIn');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        $output = curl_exec($ch);
                        curl_close($ch);
                        $curlResult = json_decode($output);
                        $finalResult = (array) $curlResult;
                        generate_logs_by_folder_structure('2-Susco Transfer Request Curl Request - ' . $customer_id, $data_string, $curlResult, 'Susco Transfer Request Curl Request', 'Susco');
                        if (!empty($finalResult)) {
                            
                            if (isset($finalResult['success']) && $finalResult['success'] == true && $finalResult['statusCode']=="200" ) { //Success
                                //Generate transaction
                                $susco_points = $finalResult['total_amount']; // susco points values
                                $transData = $this->generateConvertOutTransaction($transactionID,$susco_points,$amount, $customer_id, $data['point_issuer_id'],$finalResult['transaction_id']);
                                $status = 'OK';
                                $message = trans('api_messages.success.data_found');
                                $responseCode = '200';
                            } else {
                                $message = trans('api_messages.failure.network_error');
                                if (isset($finalResult['msg']) && $finalResult['msg'] != '') {
                                    $message = $finalResult['msg'];
                                }
                            }
                        } else {
                            $message = trans('api_messages.failure.network_error');
                            if (isset($curlResult->msg) && !empty($curlResult->msg)) {
                                $message = $curlResult->msg;
                            }
                        }
                    } catch (\Exception $e) {
                        \Log::info($customer_id.'-susco-renderResponse function error ' . $e->getMessage() . ' at line no. ' . $e->getLine() . ' in file ' . $e->getFile());
                    }
                } else {
                    $message = trans('api_messages.failure.insufficient_balance_for_convert');
                }
            } else {
                $message = trans('api_messages.failure.point_issuer_not_exist');
            }
        }

        $response['status'] = $status;
        $response['message'] = $message;
        $response['data'] = $transData;
        return response()->json($response, $responseCode);
    }

    public function generateConvertOutTransaction($transactionID, $susco_points, $total_amount, $customer_id, $point_issuer_id,$external_transaction_id) {
        $remarks = $total_amount.'xcashpoint = '.$susco_points.'susco points';
        $transData['transaction_id'] = $transactionID;
        $transData['external_transaction_id'] = $external_transaction_id;
        $transData['customer_id'] = $customer_id;
        $transData['amount'] = $total_amount;
        $transData['redeemed_points'] = '0.00';
        $transData['total_amount'] = $total_amount;
        $transData['paid_by'] = '3';
        $transData['type'] = '14'; //Convert Out        
        $transData['payment_via'] = '1';
        $transData['point_issuer_id'] = $point_issuer_id;
        $transData['remarks'] = $remarks;
        $transaction = Transaction::create($transData);

        if ($transaction) {
            TransactionHelper::saveTransactionAdditionalInfo($transaction->id);
        }

        //Update Customer
        $customerPoints = Customer::where('id', $customer_id)->first();

        Customer::where('id', $customer_id)->update([
            'available_points' => DB::raw('available_points-' . $total_amount),
            'points' => DB::raw('points-' . $total_amount),
        ]);

        if ($customerPoints['device_token'] != '') {
            //$converted_amount = $data['amount'];
            if (isset($point_issuer_id)) {
                $point_issuer = PointIssuer::where('id', $point_issuer_id)->first();
                if ($point_issuer) {
                    $point_issuer_name_en = $point_issuer->translate('en')->point_issuer_name;
                    $point_issuer_name_th = $point_issuer->translate('th')->point_issuer_name;
                    $message = 'You have converted ' . $total_amount . ' xCash to ' . $point_issuer_name_en . '.คุณได้แปลง  ' . $total_amount . ' ไปยัง ' . $point_issuer_name_th;
                } else {
                    $message = 'You have converted ' . $total_amount . ' xCash.คุณได้แปลง ' . $total_amount . ' ไปยัง (ชื่อผู้ออกพ้อยท์)';
                }
            } else {
                $message = 'You have converted ' . $total_amount . ' xCash.คุณได้แปลง ' . $total_amount . ' ไปยัง (ชื่อผู้ออกพ้อยท์)';
            }
            UserNotification::send_customer_push_notification($message, $customerPoints['device_token'], '14', 'convert out', $customer_id);
        }
        return $transData;
    }

}
