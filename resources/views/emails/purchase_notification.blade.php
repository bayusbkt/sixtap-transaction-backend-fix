<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi Pembelian</title>
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
            border-bottom: 2px solid #2196F3;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #2196F3;
            margin: 0;
        }

        .success-icon {
            font-size: 48px;
            color: #2196F3;
            margin-bottom: 10px;
        }

        .content {
            margin-bottom: 30px;
        }

        .highlight {
            background-color: #e3f2fd;
            padding: 15px;
            border-left: 4px solid #2196F3;
            margin: 20px 0;
        }

        .amount {
            font-size: 18px;
            font-weight: bold;
            color: #ff5722;
        }

        .balance {
            font-size: 18px;
            color: #4CAF50;
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
            <div class="success-icon">💳</div>
            <h1>Transaksi Berhasil!</h1>
        </div>

        <div class="content">
            <p>Halo <strong>{{ $data['user_name'] }}</strong>,</p>

            <p>Transaksi pembelian Anda di kantin telah berhasil diproses!</p>

            <div class="highlight">
                <h3>Detail Transaksi:</h3>
                <p><strong>Jumlah Pembelian:</strong> <span class="amount">Rp
                        {{ number_format($data['amount'], 0, ',', '.') }}</span></p>
                <p><strong>Kasir:</strong> {{ $data['canteen_opener'] }}</p>
                <p><strong>Sisa Saldo:</strong> <span class="balance">Rp
                        {{ number_format($data['remaining_balance'], 0, ',', '.') }}</span></p>
            </div>

            <div class="details">
                <p><strong>ID Transaksi:</strong> {{ $data['transaction_id'] }}</p>
                <p><strong>Tanggal Transaksi:</strong> {{ $data['date'] }}
                </p>
                <p><strong>Waktu Transaksi:</strong> {{ $data['timestamp']}} WIB
                </p>

            </div>

            <p>Sisa saldo Anda sekarang adalah <br><strong>Rp
                    {{ number_format($data['remaining_balance'], 0, ',', '.') }}</strong>.</p>

            @if ($data['remaining_balance'] < 10000)
                <div style="background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 15px 0;">
                    <p><strong>⚠️ Peringatan:</strong> Saldo Anda sudah rendah. Pertimbangkan untuk melakukan top up
                        agar tidak kehabisan saldo.</p>
                </div>
            @endif
        </div>

        <div class="footer">
            <p>Email ini dikirim secara otomatis oleh sistem SixTap Sekolah.</p>
            <p>Jika ada pertanyaan, silakan hubungi administrator sekolah.</p>
        </div>
    </div>
</body>

</html>
