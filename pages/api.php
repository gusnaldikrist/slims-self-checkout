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
ob_start();

/**
 * Output helper untuk memastikan respon selalu JSON murni tanpa tercampur warning/notice HTML
 */
function sendJsonResponse(array $data, int $httpCode = 200): void
{
    if (ob_get_level()) {
        $dirtyOutput = ob_get_clean();
        if (!empty(trim($dirtyOutput))) {
            error_log("SLiMS Self-Checkout API buffer output: " . $dirtyOutput);
        }
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../lib/CheckoutEngine.php';

use SLiMS\Plugins\SelfCheckout\CheckoutEngine;

try {
    $engine = new CheckoutEngine();
} catch (\Throwable $e) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Gagal menginisialisasi sistem: ' . $e->getMessage()
    ], 500);
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
            sendJsonResponse([
                'status' => false,
                'message' => 'ID Anggota dan Tanggal Lahir wajib diisi.'
            ]);
        }

        $result = $engine->authenticateMember($memberId, $birthDate);
        if (!empty($result['status'])) {
            $memberData = $result['data'] ?? ($result['member'] ?? []);
            $result['data'] = $memberData;
            $result['member'] = $memberData;
        }
        sendJsonResponse($result);
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
            sendJsonResponse([
                'status' => false,
                'message' => 'Silakan scan atau masukkan barcode eksemplar.'
            ]);
        }

        if (empty($memberId)) {
            sendJsonResponse([
                'status' => false,
                'message' => 'Sesi anggota tidak ditemukan. Silakan login kembali.'
            ]);
        }

        $result = $engine->validateAndScanItem($itemCode, $memberId, $cart);
        if (!empty($result['status'])) {
            $itemData = $result['data'] ?? ($result['item'] ?? []);
            $result['data'] = $itemData;
            $result['item'] = $itemData;
        }
        sendJsonResponse($result);
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
            sendJsonResponse([
                'status' => false,
                'message' => 'Sesi anggota tidak valid. Silakan login kembali.'
            ]);
        }

        if (empty($items) || !is_array($items)) {
            sendJsonResponse([
                'status' => false,
                'message' => 'Tidak ada buku dalam daftar peminjaman.'
            ]);
        }

        $result = $engine->processCheckout($memberId, $items);
        if (!empty($result['status'])) {
            $loansList = $result['loans'] ?? ($result['data']['items'] ?? []);
            $result['loans'] = $loansList;
        }
        sendJsonResponse($result);
        break;

    case 'get_active_loans':
    case 'active_loans':
        $memberId = trim($inputData['member_id'] ?? ($_GET['member_id'] ?? ''));
        if (empty($memberId)) {
            sendJsonResponse([
                'status' => false,
                'message' => 'ID Anggota wajib disertakan.'
            ]);
        }

        $result = $engine->getActiveLoans($memberId);
        sendJsonResponse($result);
        break;

    case 'renew_loan':
    case 'renew':
        $memberId = trim($inputData['member_id'] ?? '');
        $itemCode = trim($inputData['item_code'] ?? '');

        if (empty($memberId) || empty($itemCode)) {
            sendJsonResponse([
                'status' => false,
                'message' => 'ID Anggota dan Kode Eksemplar wajib diisi untuk perpanjangan.'
            ]);
        }

        $result = $engine->renewLoan($memberId, $itemCode);
        sendJsonResponse($result);
        break;

    case 'cancel':
        sendJsonResponse([
            'status' => true,
            'message' => 'Sesi transaksi peminjaman mandiri berhasil dibatalkan.'
        ]);
        break;

    default:
        sendJsonResponse([
            'status' => false,
            'message' => 'Aksi "' . htmlspecialchars($action) . '" tidak dikenal.'
        ], 400);
        break;
}
exit;
