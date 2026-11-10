<?php
/**
 * SLiMS Self-Checkout API Controller
 * Endpoint AJAX untuk autentikasi anggota, pemindaian eksemplar, dan proses peminjaman mandiri.
 *
 * @author Gusnaldi Kristiadi Syah
 */

defined('INDEX_AUTH') || die(json_encode(['status' => false, 'message' => 'Direct access not allowed!']));

// Bersihkan output buffer sebelumnya agar respon JSON murni
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/CheckoutEngine.php';

use SLiMS\Plugins\SelfCheckout\CheckoutEngine;

try {
    $engine = new CheckoutEngine();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'Gagal menginisialisasi sistem: ' . $e->getMessage()
    ]);
    exit;
}

// Baca input (Mendukung application/json dan form-data / x-www-form-urlencoded)
$inputData = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $jsonDecoded = json_decode($rawBody, true);
    if (is_array($jsonDecoded)) {
        $inputData = array_merge($inputData, $jsonDecoded);
    }
}

$action = trim($_GET['action'] ?? ($inputData['action'] ?? ''));

switch ($action) {
    case 'verify_member':
    case 'auth_member':
        $memberId = trim($inputData['member_id'] ?? '');
        $birthDate = trim($inputData['birth_date'] ?? '');

        if (empty($memberId) || empty($birthDate)) {
            echo json_encode([
                'status' => false,
                'message' => 'ID Anggota dan Tanggal Lahir wajib diisi.'
            ]);
            exit;
        }

        $result = $engine->authenticateMember($memberId, $birthDate);
        if (!empty($result['status'])) {
            $memberData = $result['data'] ?? ($result['member'] ?? []);
            $result['data'] = $memberData;
            $result['member'] = $memberData;
        }
        echo json_encode($result);
        break;

    case 'scan_item':
        $itemCode = trim($inputData['item_code'] ?? '');
        $memberId = trim($inputData['member_id'] ?? '');
        $cart = $inputData['cart'] ?? [];

        if (is_string($cart)) {
            $decodedCart = json_decode($cart, true);
            if (!is_array($decodedCart)) {
                $decodedCart = json_decode(stripslashes($cart), true);
            }
            $cart = is_array($decodedCart) ? $decodedCart : [];
        }

        if (empty($itemCode)) {
            echo json_encode([
                'status' => false,
                'message' => 'Silakan scan atau masukkan barcode eksemplar.'
            ]);
            exit;
        }

        if (empty($memberId)) {
            echo json_encode([
                'status' => false,
                'message' => 'Sesi anggota tidak ditemukan. Silakan login kembali.'
            ]);
            exit;
        }

        $result = $engine->validateAndScanItem($itemCode, $memberId, $cart);
        if (!empty($result['status'])) {
            $itemData = $result['data'] ?? ($result['item'] ?? []);
            $result['data'] = $itemData;
            $result['item'] = $itemData;
        }
        echo json_encode($result);
        break;

    case 'checkout':
        $memberId = trim($inputData['member_id'] ?? '');
        $items = $inputData['items'] ?? ($inputData['item_codes'] ?? []);

        if (is_string($items)) {
            $decodedItems = json_decode($items, true);
            if (!is_array($decodedItems)) {
                $decodedItems = json_decode(stripslashes($items), true);
            }
            $items = is_array($decodedItems) ? $decodedItems : [];
        }

        if (empty($memberId)) {
            echo json_encode([
                'status' => false,
                'message' => 'Sesi anggota tidak valid. Silakan login kembali.'
            ]);
            exit;
        }

        if (empty($items) || !is_array($items)) {
            echo json_encode([
                'status' => false,
                'message' => 'Tidak ada buku dalam daftar peminjaman.'
            ]);
            exit;
        }

        $result = $engine->processCheckout($memberId, $items);
        if (!empty($result['status'])) {
            $loansList = $result['loans'] ?? ($result['data']['items'] ?? []);
            $result['loans'] = $loansList;
        }
        echo json_encode($result);
        break;

    case 'get_active_loans':
    case 'active_loans':
        $memberId = trim($inputData['member_id'] ?? ($_GET['member_id'] ?? ''));
        if (empty($memberId)) {
            echo json_encode([
                'status' => false,
                'message' => 'ID Anggota wajib disertakan.'
            ]);
            exit;
        }

        $result = $engine->getActiveLoans($memberId);
        echo json_encode($result);
        break;

    case 'renew_loan':
    case 'renew':
        $memberId = trim($inputData['member_id'] ?? '');
        $itemCode = trim($inputData['item_code'] ?? '');

        if (empty($memberId) || empty($itemCode)) {
            echo json_encode([
                'status' => false,
                'message' => 'ID Anggota dan Kode Eksemplar wajib diisi untuk perpanjangan.'
            ]);
            exit;
        }

        $result = $engine->renewLoan($memberId, $itemCode);
        echo json_encode($result);
        break;

    case 'cancel':
        echo json_encode([
            'status' => true,
            'message' => 'Sesi transaksi peminjaman mandiri berhasil dibatalkan.'
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => 'Aksi "' . htmlspecialchars($action) . '" tidak dikenal.'
        ]);
        break;
}
exit;
