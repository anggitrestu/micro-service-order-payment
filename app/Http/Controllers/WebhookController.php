<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentLog;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function midtransHandler(Request $request)
    {
        $data = $request->all();
        // mengambil beberapa data untuk membuat signature key
        $signatureKey = $data['signature_key'];
        $orderId = $data['order_id'];
        $statusCode = $data['status_code'];
        $grossAmount = $data['gross_amount'];
        $serverKey = env('MIDTRANS_SERVER_KEY');
        // membuat suignature ket
        $mySignatureKey = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        $transactionStatus = $data['transaction_status'];
        $paymentType = $data['payment_type'];
        $fraudStatus = $data['fraud_status'];
        // mencocokan apakah signature key invalid
        if ($signatureKey !== $mySignatureKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'invalid signature'
            ], 400);
        }
        // 9 - adasd1 mengeckplode untuk mengambil array yang pertama
        // lanjut ke pengecekan order id apakah tersedia
        $realOrderId = explode('-', $orderId);
        $order = Order::find($realOrderId[0]);

        if (!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'order id not found'
            ], 404);
        }
        // apakah data order tersedia
        if ($order->status === 'success') {
            return response()->json([
                'status' => 'error',
                'message' => 'operation not permitted'
            ], 405);
        }

        // Sample transactionStatus handling logic
        // apakah status nya sudah success apa belum
        // jika belum, maka error handling dari midtrans digunakan

        if ($transactionStatus == 'capture') {
            if ($fraudStatus == 'challenge') {
                $order->status = 'challenge';
            } else if ($fraudStatus == 'accept') {
                $order->status = 'success';
            }
        } else if ($transactionStatus == 'settlement') {
            $order->status = 'success';
        } else if (
            $transactionStatus == 'cancel' ||
            $transactionStatus == 'deny' ||
            $transactionStatus == 'expire'
        ) {
            $order->status = 'failure';
        } else if ($transactionStatus == 'pending') {
            $order->status = 'pending';
        }
        // setelag mengubah order status, kita menyimpan payment log nya di database    
        // raw response adalah keseluruhan data
        $logData = [
            'status' => $transactionStatus,
            'raw_response' => json_encode($data),
            'order_id' => $realOrderId[0],
            'payment_type' => $paymentType,

        ];

        PaymentLog::create($logData);
        $order->save();
        // jika order status adalah success
        if ($order->status === 'success') {
            // memberikan akses premium ke service course
            createPremiumAccess([
                'user_id' => $order->user_id,
                'course_id' => $order->course_id
            ]);
        }

        return response()->json('OK');
    }
}
