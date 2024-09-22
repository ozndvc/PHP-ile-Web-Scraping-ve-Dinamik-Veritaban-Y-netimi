<?php
set_time_limit(1000);
// Veritabanı bağlantısı
$dsn = 'mysql:host=localhost;dbname=datatake;charset=utf8';
$username = 'root';
$password = '';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $username, $password, $options);

// cURL ile sayfa verisini çekme
function fetchPage($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL hatalarını engelle
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// DOM ve XPath kullanarak kategori adını ve ürün bilgilerini parse etme
function extractCategoryName($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $categoryNameNode = $xpath->query('//h1[@class="page-title"]'); // Kategori adını bulmak için XPath

    if ($categoryNameNode->length > 0) {
        return trim($categoryNameNode->item(0)->nodeValue);
    }

    return null;
}

// Ürünleri parse etme
function extractProducts($html) {
    $products = [];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Ürün adlarını çekmek
    $productNames = $xpath->query('//a[@class="product-item-link"]');
    // Ürün resimlerini çekmek
    $productImages = $xpath->query('//img[@class="product-image-photo"]');
    // Ürün kodlarını çekmek (EDMAC reference)
    $productCodes = $xpath->query('//div[@class="sku"]');
    
    for ($i = 0; $i < $productNames->length; $i++) {
        $code = trim($productCodes->item($i)->nodeValue);
        $code = str_replace('EDMAC reference:', '', $code); // "EDMAC reference:" kısmını temizle
        
        $products[] = [
            'name' => trim($productNames->item($i)->nodeValue),
            'image' => $productImages->item($i)->getAttribute('src'),
            'code' => trim($code)
        ];
    }

    return $products;
}

// Dinamik tablo oluşturma
function createCategoryTable($pdo, $tableName) {
    $sql = "CREATE TABLE IF NOT EXISTS $tableName (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        image TEXT NOT NULL,
        code VARCHAR(255) NOT NULL,
        category VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
    $pdo->exec($sql);
}

// Veritabanına ürünleri kaydetme
function saveProducts($pdo, $products, $tableName, $categoryName) {
    $sql = "INSERT INTO $tableName (name, image, code, category) VALUES (:name, :image, :code, :category)";
    $stmt = $pdo->prepare($sql);
    
    foreach ($products as $product) {
        $stmt->execute([
            ':name' => $product['name'],
            ':image' => $product['image'],
            ':code' => $product['code'],
            ':category' => $categoryName
        ]);
    }
}

// Sayfa sonu kontrolü (Next linki var mı?)
function hasNextPage($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $nextPage = $xpath->query('//li[@class="item pages-item-next"]/a'); // "Next" butonunun XPath'i
    return $nextPage->length > 0; // Eğer 'Next' butonu varsa, sayfa devam ediyor demektir
}

// Sayfa sayfa dolaşarak ürünleri toplama ve veritabanına kaydetme
function scrapeCategory($pdo, $categoryId) {
    $page = 1;
    $urlBase = "https://www.edmac.eu/en/filters.html?cat=$categoryId&p=";
    
    do {
        $url = $urlBase . $page;
        $html = fetchPage($url);

        // Kategori adını alalım
        $categoryName = extractCategoryName($html);
        if ($categoryName) {
            // Kategori ID'sine göre tablo adı oluştur
            $tableName = "category_" . $categoryId . "_filters";
            createCategoryTable($pdo, $tableName);
            
            // Ürünleri alalım ve kaydedelim
            $products = extractProducts($html);
            if (!empty($products)) {
                saveProducts($pdo, $products, $tableName, $categoryName);
                echo "Sayfa $page ($categoryName) kaydedildi.<br>";
            } else {
                echo "Sayfa $page boş.<br>";
            }

            $page++;
        } else {
            echo "Kategori adı alınamadı.<br>";
            break;
        }
    } while (hasNextPage($html)); // Sonraki sayfa var mı kontrolü
}

// Kategori ID'lerini belirle
$categories = [12, 13, 14, 28]; // Kategorilerin ID'leri (örnek)

// Her kategori için scrape işlemini başlat
foreach ($categories as $categoryId) {
    scrapeCategory($pdo, $categoryId);
}

echo "Tüm kategoriler kaydedildi!";
?>