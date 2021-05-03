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

class The1ServicesController extends Controller
{
    /**
     *  headers : {"content-type":"Application/json","accept":"Application/json","device-token":"1235656","device-type":"ios","app-version":"1.0","access-token":"","Accept-Language":"en","merchant-id":""}
     */
    public function __construct(Request $request)
    {
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
     * function name 	: generateThe1Code
     * input 		: {}
     * Method           : GET
     * Description      : generate voucher codes for the1
    */
    
    public function generateThe1Code(Request $request){            
        for($i=1; $i<= 30;$i++){
            //Save into  voucher_codes table
            $data['code'] = mt_rand(1000000, 9999999);
            $data['points'] = 100.00;  
            $data['status'] = 1;                                        
            The1Code::create($data);
        }            
        die;  
    }
    
    /* 	
     * function name 	: redeemThe1Code 
     * input 		: {"code":"123456","point_issuer_id":"1"}
     * Method           : POST
    */
    public function redeemThe1Code(Request $request){ 
        
        $status= 'FAIL'; $responseCode = '400'; $transData = $message = '';
        $app_version = $this->app_version;
        $device_type = $this->device_type;
        $customer_id = $this->customer_id;
        $language = $this->language;
        
        $data = $request->all();        
        $rules = ['code' => 'required','point_issuer_id'=>'required'];
        $validator = \Validator::make($request->all(), $rules);
        
        if ($validator->fails()){
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);     
        } 
        
        if($validator->passes()){       
            
            $pointIssuer = PointIssuer::where('id',$data['point_issuer_id'])->where('partner_type','5')->where('status','1')->first();               
            generate_logs_by_folder_structure('redeemThe1Code',$data,$pointIssuer,'redeemThe1Code ends here','The1');
            if($pointIssuer){
                
                $conversion_rate = $pointIssuer['conversion_rate'];
                
                $code = $data['code'];
                $codeInfo = The1Code::where('code',$code)->where('status','1')->first(); 
                
                generate_logs_by_folder_structure('redeemThe1Code - 1',[],$codeInfo,'redeemThe1Code ends here','The1');

                if($codeInfo){

                    $codeAmount = $codeInfo->points;                
                    $type = '10'; //Convert In online
                    $paid_by = '3';
                    $remarks = 'Convert In by The1 code '.$code.' Points: '.$codeAmount;
                    $status = 1;
                    $trans_id = round(microtime(true) * 1000);
                    
                    //$converted_amount = $codeAmount/$conversion_rate;

                    $transData = $this->generateTransaction($trans_id,$data['point_issuer_id'],$conversion_rate,$code,$codeAmount,$customer_id,$type,$paid_by,$codeInfo->tier_id,$remarks,$status);
                    
                    generate_logs_by_folder_structure('redeemThe1Code - 2',[],$transData,'redeemThe1Code ends here','The1');

                    $status = 'OK';
                    $message = trans('api_messages.success.sucessfully_transaction');
                    $responseCode = '200';               

                }else{                
                    $message = trans('api_messages.failure.the1_code_not_exist');
                    $existCodeInfo = The1Code::where('code',$code)->where('status','2')->first(); 
                    if($existCodeInfo){
                        $message = trans('api_messages.failure.the1_code_already_used');
                    }
                    generate_logs_by_folder_structure('redeemThe1Code - 3',$existCodeInfo,$message,'redeemThe1Code ends here','The1');
                }
            
            }else{
                $message = trans('api_messages.failure.point_issuer_not_exist');
            }
        }        
        $response['status'] = $status;
        $response['message'] = $message;
        $response['data'] = $transData;       	
        return response()->json($response,$responseCode);        
    }
    
    /*
      * function : generateTransaction
      * Description : common function to do transaction and more 
    */
    
    public function generateTransaction($transaction_id,$point_issuer_id,$conversion_rate,$code,$codeAmount,$customer_id,$type,$paid_by,$the1_tier_id,$remarks,$status){
                
        //Generate Transaction
        $transData['transaction_id'] = $transaction_id;
        $transData['customer_id'] = $customer_id;
        $transData['amount'] = $codeAmount;
        $transData['redeemed_points'] = '0.00';
        $transData['total_amount'] = $codeAmount;
        $transData['convert_in_code'] = $code; 
        $transData['paid_by'] = $paid_by;
        $transData['type'] = $type; 
        $transData['payment_via'] = '0'; 
        $transData['point_issuer_id'] = $point_issuer_id;
        $transData['the1_tier_id'] = $the1_tier_id;
        $transData['conversion_rate'] = $conversion_rate;
        $transData['remarks'] = $remarks; 
        $transData['status'] = $status;              
        $transaction = Transaction::create($transData);

        if($transaction){  
            
            TransactionHelper::saveTransactionAdditionalInfo($transaction->id);
            
            //update code status as used
            The1Code::where('code',$code)->update(['status'=>2]);
            
            // Save Notification 
            $notifyData['transaction_id'] = $transData['transaction_id']; 
            Notification::create($notifyData);
            
            //update customer balance
            Customer::where('id',$customer_id)->update([
                    'available_points'=> DB::raw('available_points+'.$codeAmount),
                    'points'=> DB::raw('points+'.$codeAmount),
                  ]);
            
            //Send notification
            $customer = Customer::where('id',$customer_id)->first();
            if($customer['device_token'] != ''){  
                $point_issuer_name = 'Point Issuer';
                $pointIssuer = PointIssuer::where('id',$point_issuer_id)->where('status','1')->first(); 
                if($pointIssuer){
                    $point_issuer_name = $pointIssuer->point_issuer_name;
                }
                $message = 'You have converted '.$codeAmount.' xCash from '.$point_issuer_name.'. คุณได้แปลง xCash จำนวนมูลค่า '.$codeAmount.'  จาก '.$point_issuer_name.' (ชื่อผู้ออกพ้อยท์)';                
                UserNotification::send_customer_push_notification( $message,$customer['device_token'],'10','Convert In',$customer_id);
            }            
        } 
       
        return $transData;
    }
    
    /* function name    : getThe1Tiers
     * input            : {}
     * Method           : GET
     * Description      : list Of all the1 tiers
    */    
    public function getThe1Tiers(Request $request){
        $status= 'FAIL'; $message = $data = []; $responseCode = '400';        
        $language = $this->language;
        $customer_id = $this->customer_id;
        
        $tiers = The1CodeTier::where('status','1')->where('type','2')->get();
        if($tiers){            
            $i=0;
            foreach($tiers as $tier){
                $data[$i]['id'] = $tier->id;
                $data[$i]['the1_points'] = $tier->the1_points;
                $data[$i]['xcash_points'] = $tier->xcash_points;
                $data[$i]['title'] = $tier->title;
                $the1Code = The1Code::where('tier_id',$tier->id)->where('status','1')->inRandomOrder()->first();
                if($the1Code){
                    $data[$i]['the1_code_id'] = $the1Code->id;
                }
                $i++;
            }       
            $status = 'OK';
            $message = trans('api_messages.success.data_found');
            $responseCode = '200';     
            generate_logs_by_folder_structure('getThe1Tiers',$customer_id,$data,'getThe1Tiers ends here','The1');
        }
        $response['status'] = $status;
        $response['message']= $message;
        $response['data']   = $data; 
        return response()->json($response,$responseCode);
    } 
    
    /* 	
     * function name 	: verifyThe1Member 
     * input 		: {"card_number":"1-12345"}
     * Method           : POST
    */
    public function verifyThe1Member(Request $request){ 
        
        $status= 'FAIL'; $responseCode = '400'; $message = ''; $result = '';
        $app_version = $this->app_version;
        $device_type = $this->device_type;
        $customer_id = $this->customer_id;
        $language = $this->language; 
        $system_ip = $_SERVER['REMOTE_ADDR'];
        $data = $request->all(); 
        
        $rules = ['card_number' => 'required'];
        $validationMesssages = ['card_number.required'=> trans('api_messages.failure.card_number_error')];        
        $validator = \Validator::make($request->all(), $rules,$validationMesssages);        
        if ($validator->fails()){
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);     
        } 
       
        if($validator->passes()){
            
            $cardNo = $data['card_number'];

            $datetime = date('dmY_H:i:s:v'); //Example: 05042019_11:16:00:677 DDMMYYYY_HH24:MM:SS:MMM
            $sourceTransID = 'XCASH_'.rand(1000000,9999999).'_'.$datetime; //Example: CGO_4569400_26122016_14:20:25:004
            $headers = array(            
                "client_id: ".THE1_CLIENT_ID,
                "client_secret: ".THE1_CLIENT_SECRET,
                "content-type: application/json",
                "languagepreference: EN",
                "partnercode: ".THE1_PARTNER_CODE,
                "requesttime: ".$datetime,
                "sourcetransid: ".$sourceTransID,
                "transactionchannel: ".THE1_TRANSACTIONCHANNEL
            );

            $postData = [
                        //"memberNo" => $memberNo,
                        "cardNo" => $cardNo, 
                        //"nationalID" => '1234567890123',
                        //"passport' => 'A12345678',
                        //'documentCountryCode'=>'THA'
                        ];
            $data_string = json_encode($postData); 
            
            $logRequestData = [
                'customer_id' => $customer_id,
                'cardNo' => $cardNo,
                'ip_address'=>$system_ip
            ];
            
            generate_logs_by_folder_structure('The1 Request initiate',$logRequestData,$headers,'The1 Request initiate','The1');

            try{
                //dd('here');
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, THE1_MEMBER_URL);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_POST,           true );
                curl_setopt($ch, CURLOPT_POSTFIELDS,     $data_string );
                curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: application/json'));
                curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);            
                $output = curl_exec($ch);
                curl_close($ch);            
                $curlResult = json_decode($output);
                
                //print_r($curlResult); die;
                generate_logs_by_folder_structure('The1 Request Curl Request - '.$customer_id,$logRequestData,$curlResult,'The1 Request  Curl Request','The1');
                
                if($curlResult){
                    if(isset($curlResult->responseBody) && $curlResult->responseBody->members[0]->isMember == 'Y' && ( $curlResult->responseBody->members[0]->memberStatus == 'Active' || $curlResult->responseBody->members[0]->memberStatus == 'Hold' ) ){
                        $name = $curlResult->responseBody->members[0]->memberFirstNameEng.' '.$curlResult->responseBody->members[0]->memberLastNameEng;
                        if($language == 'th'){
                           $name = $curlResult->responseBody->members[0]->memberFirstNameThai.' '.$curlResult->responseBody->members[0]->memberLastNameThai; 
                        }                        
                        if(empty(trim($name))){
                            $customerInfo = Customer::where('id',$customer_id)->select('first_name','last_name')->first();
                            $name = $customerInfo['first_name'].' '.$customerInfo['last_name'];
                        }                        
                        $cardNumber = '';
                        if($curlResult->responseBody->members[0]->cards)
                            $cardNumber = $curlResult->responseBody->members[0]->cards[0]->cardNo;

                        $result = [
                            'card_number' => $cardNo,
                            'name' => $name,
                            'cards' => $cardNumber
                        ];
                        $status = 'OK';
                        $message = trans('api_messages.success.data_found');
                        $responseCode = '200';
                    }/*elseif(isset($curlResult->responseBody) && $curlResult->responseBody->members[0]->isMember == 'Y' && $curlResult->responseBody->members[0]->memberStatus == 'Hold'){ //8076582918
                        $message = trans('api_messages.the1.hold_account');
                    }*/elseif(isset($curlResult->responseBody) && $curlResult->responseBody->members[0]->memberStatus == 'Closed'){ //2700725498
                        $message = trans('api_messages.the1.closed_account');
                    }elseif(isset($curlResult->responseBody) && $curlResult->responseBody->members[0]->memberStatus == 'Inactive'){ 
                        $message = trans('api_messages.the1.inactive_account');
                    }else{ 
                        $message = trans('api_messages.failure.network_error');
                        if(isset($curlResult->displayErrorMessage) && !empty($curlResult->displayErrorMessage)){
                            $message = $curlResult->displayErrorMessage;
                        }
                    }
                }
            }
            catch(\Exception $e){
                //echo 'renderResponse function error ' . $e->getMessage() . ' at line no. ' . $e->getCode() . ' in file ' . $e->getFile();
                \Log::info('renderResponse function error ' . $e->getMessage() . ' at line no. ' . $e->getCode() . ' in file ' . $e->getFile());
            }                           

        }
        
        $response['status'] = $status;
        $response['message'] = $message;
        $response['data'] = $result;       	
        return response()->json($response,$responseCode);        
    }
    
    /* function name    : the1ConvertOut
     * input            : {"point_issuer_id":"1","tier_id":"1","card_number":"1235656","member_name":"abc"}
     * Method           : POST
     * Description      : Create convert out transaction
    */    
    public function the1ConvertOut(Request $request){
        $status= 'FAIL'; $message = $transData = []; $responseCode = '400';        
        $language = $this->language;
        $customer_id = $this->customer_id;
        $system_ip = $_SERVER['REMOTE_ADDR'];
        $data = $request->all(); 
        
        $rules = ['point_issuer_id'=>'required','tier_id'=>'required','card_number'=>'required','member_name'=>'required'];              
        $validator = \Validator::make($request->all(), $rules);        
        if ($validator->fails()){
            $messages = $validator->messages()->toArray();
            $message = $this->getErrors($messages);     
        }         
        if($validator->passes()){ 
            
            $point_issuer = PointIssuer::where('id',$data['point_issuer_id'])->where('partner_type','5')->where('status','1')->first();            
            if($point_issuer){
                
                $tierInfo = The1CodeTier::where('id',$data['tier_id'])->where('type',2)->where('status','1')->first();                
                if($tierInfo){

                    $amount = $tierInfo['xcash_points'];
                    $the1_amount = $tierInfo['the1_points'];

                    $customerInfo = Customer::where('id',$customer_id)->select('phone_number','available_points')->first();
                    $customerPoints = $customerInfo['available_points'];
                    $partner_card_number = $customerInfo['phone_number'];

                    if($amount <= $customerPoints){ 

                        //$memberNo = $data['member_id'];
                        $card_number = $data['card_number'];
                        $transactionDate = date('dmY');
                        $name = explode(' ',$data['member_name']);
                        $partnerTransactionId = uniqId();
                        //$sourceTransID = round(microtime(true) * 1000);

                        $datetime = date('dmY_H:i:s:v'); //Example: 05042019_11:16:00:677 DDMMYYYY_HH24:MM:SS:MMM
                        $sourceTransID = 'XCASH_'.rand(1000000,9999999).'_'.$datetime; //Example: CGO_4569400_26122016_14:20:25:004
                        $headers = array(            
                            "client_id: ".THE1_CLIENT_ID,
                            "client_secret: ".THE1_CLIENT_SECRET,
                            "content-type: application/json",
                            "languagepreference: EN",
                            "partnercode: ".THE1_PARTNER_CODE,
                            "requesttime: ".$datetime,
                            "sourcetransid: ".$sourceTransID,
                            "transactionchannel: ".THE1_TRANSACTIONCHANNEL
                        );

                        $postData = [
                                    "cardNo" => $card_number, 
                                    //"memberNo" => "1-12345",
                                    "partnerTransactionID" => $partnerTransactionId,
                                    "transactionDate"=> $transactionDate,
                                    "partnerCardNo"=> $partner_card_number,
                                    "partnerCustomerFirstName"=> $name[0],
                                    "partnerCustomerLastName"=> $name[1],
                                    "points"=> $the1_amount,
                                    "partnerPoints"=> $amount,
                                    "partnerPointsExpiredDate"=> "31122020"                       
                                    ];
                        $data_string = json_encode($postData); 

                        $logRequestData = [
                            'customer_id' => $customer_id,
                            'cardNo' => $card_number,
                            'ip_address'=>$system_ip
                        ];

                        generate_logs_by_folder_structure('The1 Transfer Request initiate',$logRequestData,$headers,'The1 Transfer Request initiate','The1');
                        generate_logs_by_folder_structure('The1 Transfer Request initiate-1',$postData,$headers,'The1 Transfer Request initiate','The1');

                        try{
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, THE1_TRANSFER_URL);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_POST,           true );
                            curl_setopt($ch, CURLOPT_POSTFIELDS,     $data_string );
                            curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: application/json'));
                            curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);            
                            $output = curl_exec($ch);
                            curl_close($ch);            
                            $curlResult = json_decode($output);

                            $finalResult = (array) $curlResult;
                            //print_r($curlResult); die;

                            generate_logs_by_folder_structure('The1 Transfer Request Curl Request - '.$customer_id,$postData,$curlResult,'The1 Transfer Request Curl Request','The1');

                            if(!empty($finalResult)){
                                if($finalResult['integrationStatusCode'] == '0'){ //Success

                                    //Generate transaction
                                    $transData = $this->generateConvertOutTransaction($finalResult['responseBody']->transactionNo,$the1_amount,$amount,$customer_id,$data['point_issuer_id'],$data['tier_id'],$tierInfo->title);

                                    //Save additional info
                                    $the1_additional_info = [
                                        'transaction_id' => $transData['transaction_id'],
                                        //'member_id' => $memberNo,
                                        'card_number' => $card_number,
                                        'the1_amount' => $the1_amount,
                                        'partner_transaction_id' => $partnerTransactionId
                                    ];
                                    CustomerThe1Transaction::create($the1_additional_info); 
                                    
                                    generate_logs_by_folder_structure('The1 transaction',$transData,$the1_additional_info,'The1 transaction','The1');
                                    $status = 'OK';
                                    $message = trans('api_messages.success.data_found');
                                    $responseCode = '200';
                                }else{                        
                                    $message = trans('api_messages.failure.network_error');
                                    if(isset($finalResult['displayErrorMessage']) && $finalResult['displayErrorMessage'] != ''){
                                        $message = $finalResult['displayErrorMessage'];
                                    }
                                }
                            }else{
                                $message = trans('api_messages.failure.network_error'); 
                                if(isset($curlResult->displayErrorMessage) && !empty($curlResult->displayErrorMessage)){
                                    $message = $curlResult->displayErrorMessage;
                                }
                            }
                        }
                        catch(\Exception $e){
                            \Log::info('renderResponse function error ' . $e->getMessage() . ' at line no. ' . $e->getCode() . ' in file ' . $e->getFile());
                        } 
                    }else{
                        $message = trans('api_messages.failure.insufficient_balance_for_convert');
                    }
                }else{
                    //tier not found
                    $message = trans('api_messages.failure.tier_not_found');
                }
            }else{
                $message = trans('api_messages.failure.point_issuer_not_exist');
            }
        }
        
        $response['status'] = $status;
        $response['message'] = $message;
        $response['data'] = $transData;       	
        return response()->json($response,$responseCode); 
    }
    
    public function generateConvertOutTransaction($the1_transaction_id,$the1_amount,$total_amount,$customer_id,$point_issuer_id,$the1_tier_id,$remarks=''){        
        $transData['transaction_id'] = round(microtime(true) * 1000);
        $transData['the1_transaction_id'] = $the1_transaction_id;
        $transData['customer_id'] = $customer_id;        
        $transData['amount'] = $total_amount;
        $transData['redeemed_points'] = '0.00';
        $transData['total_amount'] = $total_amount;
        $transData['paid_by'] = '3';
        $transData['type'] = '14'; //Convert Out        
        $transData['payment_via'] = '1'; 
        $transData['point_issuer_id'] = $point_issuer_id;
        $transData['the1_tier_id'] = $the1_tier_id;
        $transData['remarks'] = $remarks;
        $transaction = Transaction::create($transData);

        if($transaction){  TransactionHelper::saveTransactionAdditionalInfo($transaction->id); }
        
        //Update Customer
        $customerPoints = Customer::where('id',$customer_id)->first(); 

        Customer::where('id',$customer_id)->update([
            'available_points'=> DB::raw('available_points-'.$total_amount),
            'points'=> DB::raw('points-'.$total_amount),
          ]);

        if($customerPoints['device_token'] != ''){
            //$converted_amount = $data['amount'];
            if(isset($point_issuer_id)){
                $point_issuer = PointIssuer::where('id',$point_issuer_id)->first();
                if($point_issuer){
                    $point_issuer_name_en = $point_issuer->translate('en')->point_issuer_name;
                    $point_issuer_name_th = $point_issuer->translate('th')->point_issuer_name;
                    $message = 'You have converted '.$total_amount.' xCash to '.$point_issuer_name_en.'.คุณได้แปลง  '.$total_amount.' ไปยัง '.$point_issuer_name_th;
                }else{
                    $message = 'You have converted '.$total_amount.' xCash.คุณได้แปลง '.$total_amount.' ไปยัง (ชื่อผู้ออกพ้อยท์)';
                }
            }else{
                $message = 'You have converted '.$total_amount.' xCash.คุณได้แปลง '.$total_amount.' ไปยัง (ชื่อผู้ออกพ้อยท์)';
            }
            UserNotification::send_customer_push_notification( $message,$customerPoints['device_token'],'14','convert out',$customer_id);
        }        
        return $transData;        
    }   
    
    public function tempThe1ConvertOut(Request $request){
                     
        //1578542298988,1578542335276,1578542362870,1578542778579

        $transaction_id = '1578542778579';
        $card_number = '2003129511';
        $transactionDate = date('dmY');
        $name[0] = 'Suparak';
        $name[1] = 'Toomkosita';
        $partnerTransactionId = uniqId();
        $partner_card_number = '0819119811';
        $the1_amount = '800';
        $amount = '120';

        $datetime = date('dmY_H:i:s:v'); //Example: 05042019_11:16:00:677 DDMMYYYY_HH24:MM:SS:MMM
        $sourceTransID = 'XCASH_'.rand(1000000,9999999).'_'.$datetime; //Example: CGO_4569400_26122016_14:20:25:004
        $headers = array(            
            "client_id: ".THE1_CLIENT_ID,
            "client_secret: ".THE1_CLIENT_SECRET,
            "content-type: application/json",
            "languagepreference: EN",
            "partnercode: ".THE1_PARTNER_CODE,
            "requesttime: ".$datetime,
            "sourcetransid: ".$sourceTransID,
            "transactionchannel: ".THE1_TRANSACTIONCHANNEL
        );

        $postData = [
                    "cardNo" => $card_number, 
                    "partnerTransactionID" => $partnerTransactionId,
                    "transactionDate"=> $transactionDate,
                    "partnerCardNo"=> $partner_card_number,
                    "partnerCustomerFirstName"=> $name[0],
                    "partnerCustomerLastName"=> $name[1],
                    "points"=> $the1_amount,
                    "partnerPoints"=> $amount,
                    "partnerPointsExpiredDate"=> "31122020"                       
                    ];
        $data_string = json_encode($postData); 
        
        generate_logs_by_folder_structure('The1 transaction - '.$transaction_id,$headers,$postData,'The1 transaction','TempThe1');

        try{
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, THE1_TRANSFER_URL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_POST,           true );
            curl_setopt($ch, CURLOPT_POSTFIELDS,     $data_string );
            curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);            
            $output = curl_exec($ch);
            curl_close($ch);            
            $curlResult = json_decode($output);

            $finalResult = (array) $curlResult;

            generate_logs_by_folder_structure('The1 transaction-1',$finalResult,[],'The1 transaction','TempThe1');
            
            if(!empty($finalResult)){
                if($finalResult['integrationStatusCode'] == '0'){ //Success

                    //Update transaction
                    $the1_transaction_id = $finalResult['responseBody']->transactionNo;
                    Transaction::where('transaction_id',$transaction_id)->update(['the1_transaction_id',$the1_transaction_id]);
                    
                    //Update additional info                   
                    CustomerThe1Transaction::where('transaction_id',$transaction_id)->update(['partner_transaction_id',$partnerTransactionId]); 
                }
            }
        }
        catch(\Exception $e){
            \Log::info('renderResponse function error ' . $e->getMessage() . ' at line no. ' . $e->getCode() . ' in file ' . $e->getFile());
        }  
           
        die;
    }
}