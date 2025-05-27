<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi Top Up</title>
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
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #4CAF50;
            margin: 0;
        }

        .success-icon {
            font-size: 48px;
            color: #4CAF50;
            margin-bottom: 10px;
        }

        .content {
            margin-bottom: 30px;
        }

        .highlight {
            background-color: #e8f5e8;
            padding: 15px;
            border-left: 4px solid #4CAF50;
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
            <div class="success-icon">✅</div>
            <h1>Top Up Berhasil!</h1>
        </div>

        <div class="content">
            <p>Halo <strong>{{ $data['user_name'] }}</strong>,</p>

            <p>Top up e-wallet Anda telah berhasil diproses!</p>

            <div class="highlight">
                <h3>Detail Top Up:</h3>
                <p><strong>Jumlah Top Up:</strong> <span class="amount">Rp
                        {{ number_format($data['amount'], 0, ',', '.') }}</span></p>
                <p><strong>Saldo Terbaru:</strong> <span class="balance">Rp
                        {{ number_format($data['new_balance'], 0, ',', '.') }}</span></p>
            </div>

            <div class="details">
                <p><strong>ID Transaksi:</strong> {{ $data['transaction_id'] }}</p>
                <p><strong>Tanggal Top Up:</strong> {{ $data['date'] }}
                </p>
                <p><strong>Waktu Top Up:</strong> {{ $data['timestamp'] }} WIB
                </p>

            </div>

            <p>Saldo Anda sekarang adalah <strong>Rp {{ number_format($data['new_balance'], 0, ',', '.') }}</strong> dan
                siap digunakan untuk transaksi di kantin sekolah.</p>
        </div>

        <div class="footer">
            <p>Email ini dikirim secara otomatis oleh sistem SixTap Sekolah.</p>
            <p>Jika ada pertanyaan, silakan hubungi administrator sekolah.</p>
        </div>
    </div>
</body>

</html>
