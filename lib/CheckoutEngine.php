<?php
/**
 * SLiMS Self-Checkout Engine
 * Logika sirkulasi mandiri, validasi anggota/eksemplar, kalkulasi hari libur, dan transaksi peminjaman.
 *
 * @author Gusnaldi Kristiadi Syah
 */

namespace SLiMS\Plugins\SelfCheckout;

use PDO;
use Exception;

class CheckoutEngine
{
    /** @var PDO */
    protected $db;

    /** @var string */
    protected $configPath;

    /** @var array */
    protected $config;

    /** @var array */
    protected $holidayDays = [];

    /** @var array */
    protected $holidayDates = [];

    /**
     * Constructor
     *
     * @param PDO|null $db
     * @param string|null $configPath
     */
    public function __construct($db = null, $configPath = null)
    {
        if ($db instanceof PDO) {
            $this->db = $db;
        } elseif (class_exists('\\SLiMS\\DB')) {
            $this->db = \SLiMS\DB::getInstance();
        } else {
            throw new Exception("Database connection not provided.");
        }

        $this->configPath = $configPath ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.json';
        $this->loadConfig();
        $this->loadHolidays();
    }

    /**
     * Load config from config.json with fallback defaults
     */
    public function loadConfig(): array
    {
        $defaults = [
            'kiosk_title' => 'Peminjaman Mandiri Perpustakaan',
            'staff_uid' => 1,
            'inactivity_timeout' => 60,
            'success_timeout' => 10
        ];

        if (file_exists($this->configPath)) {
            $content = file_get_contents($this->configPath);
            $parsed = json_decode($content, true);
            if (is_array($parsed)) {
                $this->config = array_merge($defaults, $parsed);
                return $this->config;
            }
        }

        $this->config = $defaults;
        return $this->config;
    }

    /**
     * Get active config
     */
    public function getConfig(): array
    {
        return $this->config ?: $this->loadConfig();
    }

    /**
     * Save config to config.json
     */
    public function saveConfig(array $data): bool
    {
        $current = $this->getConfig();
        $merged = array_merge($current, $data);
        $this->config = $merged;

        return (bool)file_put_contents(
            $this->configPath,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Load holidays from database
     */
    protected function loadHolidays(): void
    {
        $this->holidayDays = ['Sun']; // default libur hari minggu
        $this->holidayDates = [];

        try {
            // Hari libur mingguan (hari tanpa tanggal khusus)
            $stmt = $this->db->query("SELECT holiday_dayname FROM holiday WHERE holiday_date IS NULL");
            if ($stmt) {
                $days = [];
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    if (!empty($row[0])) {
                        $days[] = trim($row[0]);
                    }
                }
                if (!empty($days)) {
                    $this->holidayDays = $days;
                }
            }

            // Tanggal libur khusus kalender
            $stmtDate = $this->db->query("SELECT holiday_date FROM holiday WHERE holiday_date IS NOT NULL ORDER BY holiday_date DESC LIMIT 365");
            if ($stmtDate) {
                while ($row = $stmtDate->fetch(PDO::FETCH_NUM)) {
                    if (!empty($row[0])) {
                        $this->holidayDates[$row[0]] = $row[0];
                    }
                }
            }
        } catch (Exception $e) {
            // Fallback diam ke default holidays jika query gagal
        }
    }

    /**
     * Authenticate Member via Member ID and Birth Date
     *
     * @param string $memberId
     * @param string $birthDate YYYY-MM-DD
     * @return array
     */
    public function authenticateMember(string $memberId, string $birthDate): array
    {
        $memberId = trim($memberId);
        $birthDate = trim($birthDate);

        if (empty($memberId) || empty($birthDate)) {
            return [
                'status' => false,
                'message' => 'ID Anggota dan Tanggal Lahir wajib diisi.'
            ];
        }

        // Cari data member
        $stmt = $this->db->prepare(
            "SELECT m.*, mt.member_type_name, mt.loan_limit, mt.loan_periode, mt.reborrow_limit
             FROM member m
             LEFT JOIN mst_member_type mt ON m.member_type_id = mt.member_type_id
             WHERE m.member_id = :mid"
        );
        $stmt->execute([':mid' => $memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return [
                'status' => false,
                'message' => 'ID Anggota tidak ditemukan dalam pangkalan data.'
            ];
        }

        // Normalisasi format input tanggal lahir (mendukung DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD, YYYY/MM/DD, atau DDMMYYYY)
        $cleanBirth = trim((string)$birthDate);
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $cleanBirth, $m)) {
            $cleanBirth = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        } elseif (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $cleanBirth, $m)) {
            $cleanBirth = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        } elseif (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $cleanBirth, $m)) {
            $cleanBirth = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        // Cek data tanggal lahir di database keanggotaan
        $dbBirth = trim((string)$member['birth_date']);
        if (empty($dbBirth) || $dbBirth === '0000-00-00') {
            return [
                'status' => false,
                'message' => 'Data tanggal lahir Anda belum tercatat di data keanggotaan SLiMS. Silakan hubungi staf sirkulasi untuk melengkapi profil Anda.'
            ];
        }

        // Ambil format YYYY-MM-DD (10 karakter)
        $dbBirth = substr($dbBirth, 0, 10);

        // Cocokkan tanggal lahir
        if ($dbBirth !== $cleanBirth) {
            return [
                'status' => false,
                'message' => 'Tanggal Lahir tidak cocok dengan data keanggotaan.'
            ];
        }

        // Cek masa aktif keanggotaan
        $today = date('Y-m-d');
        if (!empty($member['expire_date']) && $member['expire_date'] < $today) {
            return [
                'status' => false,
                'message' => 'Masa keanggotaan Anda telah berakhir pada ' . $member['expire_date'] . '. Silakan lakukan perpanjangan di meja sirkulasi.'
            ];
        }

        // Cek status pending
        if (!empty($member['is_pending'])) {
            return [
                'status' => false,
                'message' => 'Status keanggotaan Anda masih ditangguhkan (pending). Silakan hubungi staf sirkulasi.'
            ];
        }

        // Cek buku yang sedang terlambat (Overdue)
        $stmtOverdue = $this->db->prepare(
            "SELECT COUNT(*) FROM loan
             WHERE member_id = :mid AND is_lent = 1 AND is_return = 0 AND due_date < :today"
        );
        $stmtOverdue->execute([':mid' => $memberId, ':today' => $today]);
        $overdueCount = (int)$stmtOverdue->fetchColumn();

        if ($overdueCount > 0) {
            return [
                'status' => false,
                'message' => "Anda memiliki $overdueCount buku yang terlambat dikembalikan. Silakan kembalikan buku terlebih dahulu di meja sirkulasi."
            ];
        }

        // Cek denda belum lunas
        $stmtFines = $this->db->prepare(
            "SELECT COALESCE(SUM(debet), 0) - COALESCE(SUM(credit), 0) AS unpaid
             FROM fines WHERE member_id = :mid"
        );
        $stmtFines->execute([':mid' => $memberId]);
        $unpaidFines = (float)$stmtFines->fetchColumn();

        if ($unpaidFines > 0) {
            return [
                'status' => false,
                'message' => 'Anda memiliki tanggungan denda sebesar Rp ' . number_format($unpaidFines, 0, ',', '.') . '. Silakan selesaikan pembayaran di meja sirkulasi.'
            ];
        }

        // Hitung pinjaman aktif
        $stmtActive = $this->db->prepare(
            "SELECT COUNT(*) FROM loan WHERE member_id = :mid AND is_lent = 1 AND is_return = 0"
        );
        $stmtActive->execute([':mid' => $memberId]);
        $activeLoans = (int)$stmtActive->fetchColumn();

        $loanLimit = isset($member['loan_limit']) ? (int)$member['loan_limit'] : 0;
        $loanPeriode = isset($member['loan_periode']) ? (int)$member['loan_periode'] : 7;
        $remainingQuota = max(0, $loanLimit - $activeLoans);

        if ($remainingQuota <= 0) {
            return [
                'status' => false,
                'message' => "Batas maksimal peminjaman Anda ($loanLimit buku) telah tercapai. Saat ini Anda sedang meminjam $activeLoans buku."
            ];
        }

        $memberData = [
            'member_id' => $member['member_id'],
            'member_name' => $member['member_name'],
            'member_type_name' => $member['member_type_name'] ?? 'Reguler',
            'member_image' => $member['member_image'] ?? '',
            'expire_date' => $member['expire_date'],
            'loan_limit' => $loanLimit,
            'loan_periode' => $loanPeriode,
            'active_loans' => $activeLoans,
            'remaining_quota' => $remainingQuota
        ];

        return [
            'status' => true,
            'data' => $memberData,
            'member' => $memberData
        ];
    }

    /**
     * Hitung tanggal jatuh tempo (due date) dengan melompati hari libur
     */
    public function calculateDueDate(int $loanPeriodDays, string $startDate = '', string $memberExpireDate = ''): string
    {
        $startDate = $startDate ?: date('Y-m-d');
        if ($loanPeriodDays <= 0) {
            $loanPeriodDays = 7;
        }

        // Tambahkan durasi hari pinjam
        $dueDate = date('Y-m-d', strtotime("+$loanPeriodDays days", strtotime($startDate)));

        // Lompat hari libur (simbio_date jika ada, atau algoritma mandiri)
        if (class_exists('\\simbio_date')) {
            $dueDate = \simbio_date::getNextDateNotHoliday($dueDate, $this->holidayDays, $this->holidayDates);
        } else {
            // Algoritma mandiri jika simbio_date tidak termuat
            $maxIter = 30;
            while ($maxIter-- > 0) {
                $dayName = date('D', strtotime($dueDate));
                if (in_array($dayName, $this->holidayDays) || isset($this->holidayDates[$dueDate])) {
                    $dueDate = date('Y-m-d', strtotime('+1 day', strtotime($dueDate)));
                } else {
                    break;
                }
            }
        }

        // Batasi dengan tanggal kadaluwarsa anggota jika ada
        if (!empty($memberExpireDate) && $dueDate > $memberExpireDate) {
            $dueDate = $memberExpireDate;
        }

        return $dueDate;
    }

    /**
     * Validasi dan scan item barcode eksemplar
     */
    public function validateAndScanItem(string $itemCode, string $memberId, array $currentCart = []): array
    {
        $itemCode = trim($itemCode);
        if (empty($itemCode)) {
            return ['status' => false, 'message' => 'Kode barcode eksemplar tidak boleh kosong.'];
        }

        // Cek duplikasi di keranjang saat ini
        foreach ($currentCart as $cartItem) {
            if (isset($cartItem['item_code']) && strcasecmp($cartItem['item_code'], $itemCode) === 0) {
                return ['status' => false, 'message' => "Buku dengan kode barcode '$itemCode' sudah ada di daftar pinjaman Anda."];
            }
        }

        // Ambil data eksemplar & bibliografi
        $stmt = $this->db->prepare(
            "SELECT i.item_id, i.item_code, i.coll_type_id,
                    b.biblio_id, b.title, b.classification,
                    IF(i.call_number IS NULL OR i.call_number = '', b.call_number, i.call_number) AS call_number,
                    b.gmd_id, ist.no_loan, ist.item_status_name
             FROM item i
             JOIN biblio b ON i.biblio_id = b.biblio_id
             LEFT JOIN mst_item_status ist ON i.item_status_id = ist.item_status_id
             WHERE i.item_code = :code"
        );
        $stmt->execute([':code' => $itemCode]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return ['status' => false, 'message' => "Buku dengan kode barcode '$itemCode' tidak ditemukan dalam katalog."];
        }

        // Cek status no_loan (referensi / tidak untuk dipinjamkan)
        if (!empty($item['no_loan']) && (int)$item['no_loan'] > 0) {
            $statusName = $item['item_status_name'] ?: 'Referensi';
            return [
                'status' => false,
                'message' => "Buku '{$item['title']}' berstatus '$statusName' (Tidak untuk Dipinjamkan)."
            ];
        }

        // Cek apakah sedang dipinjam (oleh diri sendiri atau anggota lain)
        $stmtBorrowed = $this->db->prepare(
            "SELECT loan_id, member_id, loan_date, due_date, renewed, loan_rules_id 
             FROM loan 
             WHERE item_code = :code AND is_lent = 1 AND is_return = 0"
        );
        $stmtBorrowed->execute([':code' => $itemCode]);
        $activeLoans = $stmtBorrowed->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($activeLoans)) {
            // Cari apakah dipinjam oleh pemustaka yang sedang aktif
            $myLoan = null;
            foreach ($activeLoans as $al) {
                if ($al['member_id'] === $memberId) {
                    $myLoan = $al;
                    break;
                }
            }

            if ($myLoan) {
                $activeLoan = $myLoan;
                // Buku ini sedang dipinjam oleh pemustaka yang sedang aktif login!
                $today = date('Y-m-d');
                $isOverdue = ($activeLoan['due_date'] < $today);

                // Cek reservasi pemustaka lain
                $stmtRes = $this->db->prepare(
                    "SELECT 1 FROM reserve WHERE item_code = :code AND member_id != :mid LIMIT 1"
                );
                $stmtRes->execute([':code' => $itemCode, ':mid' => $memberId]);
                $isReserved = (bool)$stmtRes->fetchColumn();

                // Ambil profil member & batas perpanjangan
                $stmtMem = $this->db->prepare(
                    "SELECT m.expire_date, mt.member_type_id, mt.reborrow_limit AS mt_reborrow_limit, mt.loan_periode AS mt_loan_periode
                     FROM member m
                     LEFT JOIN mst_member_type mt ON m.member_type_id = mt.member_type_id
                     WHERE m.member_id = :mid"
                );
                $stmtMem->execute([':mid' => $memberId]);
                $memData = $stmtMem->fetch(PDO::FETCH_ASSOC);

                $loanPeriod = (int)($memData['mt_loan_periode'] ?? 7);
                $reborrowLimit = (int)($memData['mt_reborrow_limit'] ?? 1);

                $stmtRules = $this->db->prepare(
                    "SELECT loan_periode, reborrow_limit
                     FROM mst_loan_rules
                     WHERE member_type_id = :mtid
                       AND (coll_type_id = :coll OR coll_type_id = 0)
                       AND (gmd_id = :gmd OR gmd_id = 0)
                     ORDER BY coll_type_id DESC, gmd_id DESC
                     LIMIT 1"
                );
                $stmtRules->execute([
                    ':mtid' => (int)($memData['member_type_id'] ?? 0),
                    ':coll' => (int)$item['coll_type_id'],
                    ':gmd' => (int)$item['gmd_id']
                ]);
                $rule = $stmtRules->fetch(PDO::FETCH_ASSOC);
                if ($rule) {
                    if (isset($rule['loan_periode']) && $rule['loan_periode'] > 0) {
                        $loanPeriod = (int)$rule['loan_periode'];
                    }
                    if (isset($rule['reborrow_limit'])) {
                        $reborrowLimit = (int)$rule['reborrow_limit'];
                    }
                }

                $renewed = (int)$activeLoan['renewed'];
                $canRenew = true;
                $cannotRenewReason = '';

                if ($isOverdue) {
                    $canRenew = false;
                    $cannotRenewReason = 'Buku ini sudah terlambat jatuh tempo. Silakan lakukan penyelesaian di meja sirkulasi.';
                } elseif ($isReserved) {
                    $canRenew = false;
                    $cannotRenewReason = 'Buku ini sedang dipesan (reservasi) oleh pemustaka lain sehingga tidak dapat diperpanjang.';
                } elseif ($renewed >= $reborrowLimit) {
                    $canRenew = false;
                    $cannotRenewReason = "Batas maksimal perpanjangan ($reborrowLimit kali) untuk buku ini telah tercapai.";
                } elseif (!empty($memData['expire_date']) && $memData['expire_date'] < $today) {
                    $canRenew = false;
                    $cannotRenewReason = 'Masa berlaku keanggotaan Anda telah berakhir.';
                }

                $newDueDate = $this->calculateDueDate($loanPeriod, $today, $memData['expire_date'] ?? '');

                return [
                    'status' => true,
                    'is_renewal_candidate' => true,
                    'message' => 'Buku ini sedang Anda pinjam.',
                    'loan' => [
                        'loan_id' => (int)$activeLoan['loan_id'],
                        'item_code' => $itemCode,
                        'title' => $item['title'],
                        'loan_date' => $activeLoan['loan_date'],
                        'due_date' => $activeLoan['due_date'],
                        'renewed' => $renewed,
                        'reborrow_limit' => $reborrowLimit,
                        'can_renew' => $canRenew,
                        'cannot_renew_reason' => $cannotRenewReason,
                        'new_due_date' => $newDueDate
                    ],
                    'item' => [
                        'item_code' => $item['item_code'],
                        'title' => $item['title'],
                        'call_number' => $item['call_number'] ?: '-',
                        'classification' => $item['classification'] ?: '-'
                    ]
                ];
            } else {
                return [
                    'status' => false,
                    'message' => "Eksemplar buku '{$item['title']}' ($itemCode) saat ini sedang berstatus dipinjam oleh anggota lain."
                ];
            }
        }

        // Cek reservasi
        $stmtReserve = $this->db->prepare(
            "SELECT member_id FROM reserve WHERE item_code = :code ORDER BY reserve_date ASC LIMIT 1"
        );
        $stmtReserve->execute([':code' => $itemCode]);
        $reserver = $stmtReserve->fetchColumn();
        if ($reserver && $reserver !== $memberId) {
            return [
                'status' => false,
                'message' => "Eksemplar ini sedang di-reservasi oleh pemustaka lain."
            ];
        }

        // Ambil aturan pinjam spesifik atau tipe anggota
        $stmtRules = $this->db->prepare(
            "SELECT lr.loan_rules_id, lr.loan_limit, lr.loan_periode
             FROM mst_loan_rules lr
             JOIN member m ON m.member_id = :mid
             WHERE lr.member_type_id = m.member_type_id
               AND (lr.coll_type_id = :coll OR lr.coll_type_id = 0)
               AND (lr.gmd_id = :gmd OR lr.gmd_id = 0)
             ORDER BY lr.coll_type_id DESC, lr.gmd_id DESC
             LIMIT 1"
        );
        $stmtRules->execute([
            ':mid' => $memberId,
            ':coll' => (int)$item['coll_type_id'],
            ':gmd' => (int)$item['gmd_id']
        ]);
        $rule = $stmtRules->fetch(PDO::FETCH_ASSOC);

        $loanPeriod = $rule ? (int)$rule['loan_periode'] : 7;
        $loanRulesId = $rule ? (int)$rule['loan_rules_id'] : 0;

        // Ambil expire_date member untuk batas due date
        $stmtMem = $this->db->prepare("SELECT expire_date FROM member WHERE member_id = :mid");
        $stmtMem->execute([':mid' => $memberId]);
        $memExpire = (string)$stmtMem->fetchColumn();

        $dueDate = $this->calculateDueDate($loanPeriod, date('Y-m-d'), $memExpire);

        $itemData = [
            'item_code' => $item['item_code'],
            'biblio_id' => (int)$item['biblio_id'],
            'title' => $item['title'],
            'call_number' => $item['call_number'] ?: '-',
            'classification' => $item['classification'] ?: '-',
            'loan_rules_id' => $loanRulesId,
            'loan_date' => date('Y-m-d'),
            'due_date' => $dueDate
        ];

        return [
            'status' => true,
            'data' => $itemData,
            'item' => $itemData
        ];
    }

    /**
     * Selesaikan peminjaman (Batch Checkout Atomic)
     *
     * @param string $memberId
     * @param array $items Array of item data (item_code, due_date, etc.)
     * @param int|null $uid
     * @return array
     */
    public function processCheckout(string $memberId, array $items, int $uid = null): array
    {
        $memberId = trim($memberId);
        if (empty($memberId) || empty($items)) {
            return ['status' => false, 'message' => 'Tidak ada buku yang dipilih untuk dipinjam.'];
        }

        // UID Staf dari parameter atau konfigurasi
        if ($uid === null || $uid <= 0) {
            $config = $this->getConfig();
            $uid = (int)($config['staff_uid'] ?? 1);
        }

        // Ambil data profil member
        $stmtMem = $this->db->prepare(
            "SELECT m.member_id, m.member_name, mt.member_type_name, mt.loan_limit
             FROM member m
             LEFT JOIN mst_member_type mt ON m.member_type_id = mt.member_type_id
             WHERE m.member_id = :mid"
        );
        $stmtMem->execute([':mid' => $memberId]);
        $member = $stmtMem->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return ['status' => false, 'message' => 'Data anggota tidak valid.'];
        }

        // Hitung ulang pinjaman aktif dan pastikan tidak melebihi kuota
        $stmtActive = $this->db->prepare("SELECT COUNT(*) FROM loan WHERE member_id = :mid AND is_lent = 1 AND is_return = 0");
        $stmtActive->execute([':mid' => $memberId]);
        $currentActive = (int)$stmtActive->fetchColumn();

        $loanLimit = (int)($member['loan_limit'] ?? 0);
        if (($currentActive + count($items)) > $loanLimit) {
            return [
                'status' => false,
                'message' => "Jumlah peminjaman melebihi kuota. Kuota Anda: $loanLimit, Pinjaman aktif: $currentActive, Permintaan: " . count($items)
            ];
        }

        // Mulai Transaksi Database
        $this->db->beginTransaction();

        try {
            $now = date('Y-m-d H:i:s');
            $today = date('Y-m-d');
            $borrowedResults = [];

            $stmtInsertLoan = $this->db->prepare(
                "INSERT INTO loan
                 (item_code, member_id, loan_date, due_date, renewed, loan_rules_id, is_lent, is_return, input_date, last_update, uid)
                 VALUES
                 (:item_code, :member_id, :loan_date, :due_date, 0, :loan_rules_id, 1, 0, :input_date, :last_update, :uid)"
            );

            $stmtMeta = $this->db->prepare(
                "SELECT b.title, b.biblio_id,
                        IF(i.call_number IS NULL OR i.call_number = '', b.call_number, i.call_number) as call_number,
                        b.classification, g.gmd_name, l.language_name, loc.location_name, ct.coll_type_name
                 FROM item i
                 JOIN biblio b ON i.biblio_id = b.biblio_id
                 LEFT JOIN mst_gmd g ON b.gmd_id = g.gmd_id
                 LEFT JOIN mst_language l ON b.language_id = l.language_id
                 LEFT JOIN mst_location loc ON i.location_id = loc.location_id
                 LEFT JOIN mst_coll_type ct ON i.coll_type_id = ct.coll_type_id
                 WHERE i.item_code = :code"
            );

            $stmtInsertHistory = $this->db->prepare(
                "INSERT INTO loan_history
                 (loan_id, item_code, biblio_id, title, call_number, classification, gmd_name, language_name,
                  location_name, collection_type_name, member_id, member_name, member_type_name, loan_date,
                  due_date, renewed, is_lent, is_return, input_date, last_update)
                 VALUES
                 (:loan_id, :item_code, :biblio_id, :title, :call_number, :classification, :gmd_name, :language_name,
                  :location_name, :collection_type_name, :member_id, :member_name, :member_type_name, :loan_date,
                  :due_date, 0, 1, 0, :input_date, :last_update)"
            );

            $stmtDeleteReserve = $this->db->prepare(
                "DELETE FROM reserve WHERE member_id = :mid AND item_code = :code"
            );

            foreach ($items as $item) {
                $itemCode = trim($item['item_code']);
                $loanDate = $item['loan_date'] ?? $today;
                $dueDate = $item['due_date'] ?? $today;
                $loanRulesId = (int)($item['loan_rules_id'] ?? 0);

                // Insert ke tabel loan
                $stmtInsertLoan->execute([
                    ':item_code' => $itemCode,
                    ':member_id' => $memberId,
                    ':loan_date' => $loanDate,
                    ':due_date' => $dueDate,
                    ':loan_rules_id' => $loanRulesId,
                    ':input_date' => $now,
                    ':last_update' => $now,
                    ':uid' => $uid
                ]);

                $loanId = (int)$this->db->lastInsertId();

                // Ambil metadata untuk loan_history
                $stmtMeta->execute([':code' => $itemCode]);
                $meta = $stmtMeta->fetch(PDO::FETCH_ASSOC);

                if ($meta) {
                    $stmtInsertHistory->execute([
                        ':loan_id' => $loanId,
                        ':item_code' => $itemCode,
                        ':biblio_id' => (int)$meta['biblio_id'],
                        ':title' => $meta['title'] ?? '',
                        ':call_number' => $meta['call_number'] ?? '',
                        ':classification' => $meta['classification'] ?? '',
                        ':gmd_name' => $meta['gmd_name'] ?? '',
                        ':language_name' => $meta['language_name'] ?? '',
                        ':location_name' => $meta['location_name'] ?? '',
                        ':collection_type_name' => $meta['coll_type_name'] ?? '',
                        ':member_id' => $member['member_id'],
                        ':member_name' => $member['member_name'],
                        ':member_type_name' => $member['member_type_name'] ?? '',
                        ':loan_date' => $loanDate,
                        ':due_date' => $dueDate,
                        ':input_date' => $now,
                        ':last_update' => $now
                    ]);
                }

                // Hapus reservasi jika ada
                $stmtDeleteReserve->execute([':mid' => $memberId, ':code' => $itemCode]);

                $borrowedResults[] = [
                    'loan_id' => $loanId,
                    'item_code' => $itemCode,
                    'title' => $meta['title'] ?? ($item['title'] ?? $itemCode),
                    'due_date' => $dueDate
                ];
            }

            $this->db->commit();

            return [
                'status' => true,
                'message' => 'Peminjaman berhasil dicatat.',
                'data' => [
                    'member_id' => $member['member_id'],
                    'member_name' => $member['member_name'],
                    'total_items' => count($borrowedResults),
                    'items' => $borrowedResults
                ],
                'loans' => $borrowedResults
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'status' => false,
                'message' => 'Gagal memproses peminjaman: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Ambil daftar pinjaman aktif anggota beserta status kelayakan perpanjangan
     *
     * @param string $memberId
     * @return array
     */
    public function getActiveLoans(string $memberId): array
    {
        $memberId = trim($memberId);
        if (empty($memberId)) {
            return ['status' => false, 'message' => 'ID Anggota tidak valid.', 'data' => []];
        }

        // Ambil data member dan reborrow_limit default
        $stmtMem = $this->db->prepare(
            "SELECT m.member_id, m.member_name, m.expire_date, m.is_pending,
                    mt.member_type_id, mt.member_type_name, mt.reborrow_limit AS mt_reborrow_limit, mt.loan_periode AS mt_loan_periode
             FROM member m
             LEFT JOIN mst_member_type mt ON m.member_type_id = mt.member_type_id
             WHERE m.member_id = :mid"
        );
        $stmtMem->execute([':mid' => $memberId]);
        $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            return ['status' => false, 'message' => 'Anggota tidak ditemukan.', 'data' => []];
        }

        $today = date('Y-m-d');

        // Ambil pinjaman aktif
        $stmtLoans = $this->db->prepare(
            "SELECT l.loan_id, l.item_code, l.loan_date, l.due_date, l.renewed, l.loan_rules_id,
                    i.coll_type_id, b.biblio_id, b.title, b.classification,
                    IF(i.call_number IS NULL OR i.call_number = '', b.call_number, i.call_number) AS call_number,
                    b.gmd_id
             FROM loan l
             JOIN item i ON l.item_code = i.item_code
             JOIN biblio b ON i.biblio_id = b.biblio_id
             WHERE l.member_id = :mid AND l.is_lent = 1 AND l.is_return = 0
             ORDER BY l.due_date ASC"
        );
        $stmtLoans->execute([':mid' => $memberId]);
        $loans = $stmtLoans->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($loans as $loan) {
            $loanPeriod = (int)($member['mt_loan_periode'] ?? 7);
            $reborrowLimit = (int)($member['mt_reborrow_limit'] ?? 1);

            $stmtRules = $this->db->prepare(
                "SELECT loan_rules_id, loan_periode, reborrow_limit
                 FROM mst_loan_rules
                 WHERE member_type_id = :mtid
                   AND (coll_type_id = :coll OR coll_type_id = 0)
                   AND (gmd_id = :gmd OR gmd_id = 0)
                 ORDER BY coll_type_id DESC, gmd_id DESC
                 LIMIT 1"
            );
            $stmtRules->execute([
                ':mtid' => (int)$member['member_type_id'],
                ':coll' => (int)$loan['coll_type_id'],
                ':gmd' => (int)$loan['gmd_id']
            ]);
            $rule = $stmtRules->fetch(PDO::FETCH_ASSOC);
            if ($rule) {
                if (isset($rule['loan_periode']) && $rule['loan_periode'] > 0) {
                    $loanPeriod = (int)$rule['loan_periode'];
                }
                if (isset($rule['reborrow_limit'])) {
                    $reborrowLimit = (int)$rule['reborrow_limit'];
                }
            }

            $renewed = (int)$loan['renewed'];
            $isOverdue = ($loan['due_date'] < $today);
            
            // Cek reservasi pemustaka lain
            $stmtRes = $this->db->prepare(
                "SELECT 1 FROM reserve WHERE item_code = :code AND member_id != :mid LIMIT 1"
            );
            $stmtRes->execute([':code' => $loan['item_code'], ':mid' => $memberId]);
            $isReservedByOther = (bool)$stmtRes->fetchColumn();

            // Hitung due date baru potensial
            $newDueDate = $this->calculateDueDate($loanPeriod, $today, $member['expire_date'] ?? '');

            $canRenew = true;
            $cannotRenewReason = '';

            if ($isOverdue) {
                $canRenew = false;
                $cannotRenewReason = 'Peminjaman sudah terlambat (Overdue). Silakan hubungi meja sirkulasi.';
            } elseif ($isReservedByOther) {
                $canRenew = false;
                $cannotRenewReason = 'Buku sedang dipesan (reservasi) oleh pemustaka lain.';
            } elseif ($renewed >= $reborrowLimit) {
                $canRenew = false;
                $cannotRenewReason = "Batas maksimal perpanjangan ($reborrowLimit kali) telah tercapai.";
            } elseif (!empty($member['expire_date']) && $member['expire_date'] < $today) {
                $canRenew = false;
                $cannotRenewReason = 'Masa berlaku keanggotaan Anda telah berakhir.';
            }

            $results[] = [
                'loan_id' => (int)$loan['loan_id'],
                'item_code' => $loan['item_code'],
                'biblio_id' => (int)$loan['biblio_id'],
                'title' => $loan['title'],
                'call_number' => $loan['call_number'] ?: '-',
                'classification' => $loan['classification'] ?: '-',
                'loan_date' => $loan['loan_date'],
                'due_date' => $loan['due_date'],
                'renewed' => $renewed,
                'reborrow_limit' => $reborrowLimit,
                'is_overdue' => $isOverdue,
                'is_reserved' => $isReservedByOther,
                'can_renew' => $canRenew,
                'cannot_renew_reason' => $cannotRenewReason,
                'new_due_date' => $newDueDate
            ];
        }

        return [
            'status' => true,
            'data' => $results,
            'loans' => $results,
            'total' => count($results)
        ];
    }

    /**
     * Eksekusi Perpanjangan Masa Pinjam (Renewal)
     *
     * @param string $memberId
     * @param string $itemCode
     * @param int|null $uid
     * @return array
     */
    public function renewLoan(string $memberId, string $itemCode, int $uid = null): array
    {
        $memberId = trim($memberId);
        $itemCode = trim($itemCode);

        if (empty($memberId) || empty($itemCode)) {
            return ['status' => false, 'message' => 'ID Anggota dan Kode Eksemplar wajib diisi.'];
        }

        // Ambil data pinjaman aktif
        $stmtLoan = $this->db->prepare(
            "SELECT l.loan_id, l.item_code, l.loan_date, l.due_date, l.renewed, l.loan_rules_id,
                    i.coll_type_id, b.biblio_id, b.title, b.gmd_id
             FROM loan l
             JOIN item i ON l.item_code = i.item_code
             JOIN biblio b ON i.biblio_id = b.biblio_id
             WHERE l.member_id = :mid AND l.item_code = :code AND l.is_lent = 1 AND l.is_return = 0
             LIMIT 1"
        );
        $stmtLoan->execute([':mid' => $memberId, ':code' => $itemCode]);
        $loan = $stmtLoan->fetch(PDO::FETCH_ASSOC);

        if (!$loan) {
            return [
                'status' => false,
                'message' => "Data peminjaman aktif untuk buku '$itemCode' tidak ditemukan atas ID anggota ini."
            ];
        }

        $today = date('Y-m-d');
        if ($loan['due_date'] < $today) {
            return [
                'status' => false,
                'message' => "Buku '{$loan['title']}' sudah melewati batas tanggal jatuh tempo ({$loan['due_date']}). Perpanjangan tidak dapat dilakukan secara mandiri, silakan ke meja sirkulasi."
            ];
        }

        // Cek reservasi
        $stmtRes = $this->db->prepare(
            "SELECT 1 FROM reserve WHERE item_code = :code AND member_id != :mid LIMIT 1"
        );
        $stmtRes->execute([':code' => $itemCode, ':mid' => $memberId]);
        if ($stmtRes->fetchColumn()) {
            return [
                'status' => false,
                'message' => "Buku '{$loan['title']}' sedang dipesan (reservasi) oleh pemustaka lain sehingga tidak dapat diperpanjang."
            ];
        }

        // Ambil data member
        $stmtMem = $this->db->prepare(
            "SELECT m.member_id, m.expire_date, mt.member_type_id, mt.reborrow_limit AS mt_reborrow_limit, mt.loan_periode AS mt_loan_periode
             FROM member m
             LEFT JOIN mst_member_type mt ON m.member_type_id = mt.member_type_id
             WHERE m.member_id = :mid"
        );
        $stmtMem->execute([':mid' => $memberId]);
        $member = $stmtMem->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return ['status' => false, 'message' => 'Data anggota tidak ditemukan.'];
        }

        if (!empty($member['expire_date']) && $member['expire_date'] < $today) {
            return ['status' => false, 'message' => 'Masa berlaku keanggotaan Anda telah berakhir.'];
        }

        // Cari reborrow_limit & loan_periode
        $loanPeriod = (int)($member['mt_loan_periode'] ?? 7);
        $reborrowLimit = (int)($member['mt_reborrow_limit'] ?? 1);

        $stmtRules = $this->db->prepare(
            "SELECT loan_rules_id, loan_periode, reborrow_limit
             FROM mst_loan_rules
             WHERE member_type_id = :mtid
               AND (coll_type_id = :coll OR coll_type_id = 0)
               AND (gmd_id = :gmd OR gmd_id = 0)
             ORDER BY coll_type_id DESC, gmd_id DESC
             LIMIT 1"
        );
        $stmtRules->execute([
            ':mtid' => (int)$member['member_type_id'],
            ':coll' => (int)$loan['coll_type_id'],
            ':gmd' => (int)$loan['gmd_id']
        ]);
        $rule = $stmtRules->fetch(PDO::FETCH_ASSOC);
        if ($rule) {
            if (isset($rule['loan_periode']) && $rule['loan_periode'] > 0) {
                $loanPeriod = (int)$rule['loan_periode'];
            }
            if (isset($rule['reborrow_limit'])) {
                $reborrowLimit = (int)$rule['reborrow_limit'];
            }
        }

        $renewed = (int)$loan['renewed'];
        if ($renewed >= $reborrowLimit) {
            return [
                'status' => false,
                'message' => "Batas maksimal perpanjangan untuk buku ini ($reborrowLimit kali) telah tercapai."
            ];
        }

        // Hitung due date baru dari hari ini
        $newDueDate = $this->calculateDueDate($loanPeriod, $today, $member['expire_date'] ?? '');
        $newRenewed = $renewed + 1;
        $now = date('Y-m-d H:i:s');

        // Update database
        $nestedTx = $this->db->inTransaction();
        if (!$nestedTx) {
            $this->db->beginTransaction();
        }
        try {
            $stmtUpd = $this->db->prepare(
                "UPDATE loan 
                 SET renewed = :renewed, due_date = :due_date, last_update = :last_update
                 WHERE loan_id = :lid AND member_id = :mid"
            );
            $stmtUpd->execute([
                ':renewed' => $newRenewed,
                ':due_date' => $newDueDate,
                ':last_update' => $now,
                ':lid' => (int)$loan['loan_id'],
                ':mid' => $memberId
            ]);

            // Bila trigger database tidak aktif, kita juga pastikan sinkronisasi loan_history jika ada
            try {
                $stmtHist = $this->db->prepare(
                    "UPDATE loan_history SET renewed = :renewed WHERE loan_id = :lid"
                );
                $stmtHist->execute([':renewed' => $newRenewed, ':lid' => (int)$loan['loan_id']]);
            } catch (\Exception $e) {
                // Ignore jika trigger sudah menangani atau tabel terproteksi
            }

            if (!$nestedTx) {
                $this->db->commit();
            }

            return [
                'status' => true,
                'message' => "Masa pinjam buku '{$loan['title']}' berhasil diperpanjang hingga " . date('d/m/Y', strtotime($newDueDate)) . ".",
                'data' => [
                    'loan_id' => (int)$loan['loan_id'],
                    'item_code' => $itemCode,
                    'title' => $loan['title'],
                    'old_due_date' => $loan['due_date'],
                    'new_due_date' => $newDueDate,
                    'renewed' => $newRenewed,
                    'reborrow_limit' => $reborrowLimit
                ]
            ];
        } catch (\Exception $e) {
            if (!$nestedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'status' => false,
                'message' => 'Gagal memperpanjang masa pinjam buku: ' . $e->getMessage()
            ];
        }
    }
}
