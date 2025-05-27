<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Notifikasi Refund</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #FF9800;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #FF9800;
            margin: 0;
        }

        .success-icon {
            font-size: 48px;
            color: #FF9800;
            margin-bottom: 10px;
        }

        .content {
            margin-bottom: 30px;
        }

        .highlight {
            background-color: #fff3e0;
            padding: 15px;
            border-left: 4px solid #FF9800;
            margin: 20px 0;
        }

        .amount {
            font-size: 18px;
            font-weight: bold;
            color: #4CAF50;
        }

        .balance {
            font-size: 18px;
            color: #2196F3;
            font-weight: bold;
        }

        .details {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }

        .note {
            background-color: #e8f5e8;
            padding: 10px;
            border-radius: 5px;
            margin: 15px 0;
            font-style: italic;
        }

        .footer {
            text-align: center;
            border-top: 1px solid #ddd;
            padding-top: 20px;
            color: #666;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="success-icon">↩️</div>
            <h1>Refund Berhasil!</h1>
        </div>

        <div class="content">
            <p>Halo <strong>{{ $data['user_name'] }}</strong>,</p>
            <p>Refund untuk transaksi Anda telah berhasil diproses!</p>

            <div class="highlight">
                <h3>Detail Refund:</h3>
                <p><strong>Jumlah Refund:</strong> <span class="amount">Rp
                        {{ number_format($data['refund_amount'], 0, ',', '.') }}</span></p>
                <p><strong>Saldo Sekarang:</strong> <span class="balance">Rp
                        {{ number_format($data['new_balance'], 0, ',', '.') }}</span></p>
            </div>

            <div class="details">
                <p><strong>ID Transaksi Refund:</strong> {{ $data['refund_transaction_id'] }}</p>
                <p><strong>ID Transaksi Asli:</strong> {{ $data['original_transaction_id'] }}</p>
                <p><strong>Tanggal Refund:</strong> {{ $data['date'] }}
                </p>
                <p><strong>Waktu Refund:</strong> {{ $data['timestamp'] }} WIB
                </p>

            </div>

            @if (!empty($data['note']))
                <div class="note">
                    Catatan: {{ $data['note'] }}
                </div>
            @endif

            <p>Jika Anda merasa tidak melakukan permintaan refund ini, segera hubungi admin atau petugas kantin.</p>
        </div>

        <div class="footer">
            <p>Email ini dikirim secara otomatis oleh sistem SixTap Sekolah.</p>
            <p>Jika ada pertanyaan, silakan hubungi administrator sekolah.</p>
        </div>
    </div>
</body>

</html>
