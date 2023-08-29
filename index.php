<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once  __DIR__ . '/utils/constants.php';
require_once  __DIR__ . '/utils/database.php';
require_once  __DIR__ . '/utils/scraping.php';

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriver;
use Facebook\WebDriver\WebDriverKeys;

$envConfig = parse_ini_file(__DIR__ . "/.env");

$host = $envConfig['DB_HOST'];
$username = $envConfig['DB_USERNAME'];
$password = $envConfig['DB_PASSWORD'];
$dbname = $envConfig['DB_DATABASE'];
$apiKey = $envConfig['API_KEY'];
$db  = new Database();

// Connect to DB
if (!$db->connect($host, $username, $password, $dbname)) {
  die("DB Connection failed: " . $conn->connect_error);
}

_init();

// Set up Selenium WebDriver
$host = 'http://localhost:4444/wd/hub';
$capabilities = \Facebook\WebDriver\Remote\DesiredCapabilities::chrome();
$capabilities->setCapability('goog:chromeOptions', ['args' => ["--headless", "--user-agent=" . USER_AGENT]]);
$driver = RemoteWebDriver::create($host, $capabilities);

$properties = [];

$filterState = array(
  "beds" => array(
    "min" => 1
  ),
  "baths" => array(
    "min" => 1
  ),
  "sqft" => array(
    "min" => 500,
    "max" => 750,
  ),
  "pmf" => array(
    "value" => true
  ),
  "sort" => array(
    "value" => "globalrelevanceex"
  ),
  "nc" => array(
    "value" => false
  ),
  "fsbo" => array(
    "value" => false
  ),
  "cmsn" => array(
    "value" => false
  ),
  "pf" => array(
    "value" => true
  ),
  "fsba" => array(
    "value" => false
  )
);

$query = array(
  "pagination" => new stdClass(),
  "usersSearchTerm" => "CA",
  "filterState" => $filterState,
  "isListVisible" => true
);

$queryString = json_encode($query);
$searchQueryState = urlencode($queryString);
$url = "https://api.scrapingdog.com/scrape?api_key=$apiKey&url=https://www.zillow.com/ca/?searchQueryState=$searchQueryState";

$driver->get($url);

try {
  $totalCount = $driver->findElement(WebDriverBy::cssSelector("div.ListHeader__NarrowViewWrapping-srp__sc-1rsgqpl-1.idxSRv.search-subtitle span.result-count"))->getText();
  $pattern = '/\d+/';

  preg_match('/\d+/', $totalCount, $matches);

  if (isset($matches[0])) {
    $totalCount = intval($matches[0]);
    $itemsPerPage = 41;


    $currentPage = 1;
    $maxPage = ceil($totalCount / $itemsPerPage);

    while ($currentPage <= $maxPage) {
      if ($currentPage !== 1) {
        $pagination = array(
          "currentPage" => $currentPage,
        );
        $query["pagination"] = $pagination;
      }

      $queryString = json_encode($query);
      $searchQueryState = urlencode($queryString);
      $pageUrl = "https://api.scrapingdog.com/scrape?api_key=$apiKey&url=https://www.zillow.com/ca/?searchQueryState=$searchQueryState";
      $driver->get($pageUrl);

      $html = $driver->findElement(WebDriverBy::tagName('html'));
      $html->sendKeys(WebDriverKeys::END);
      sleep(5);

      $propertyElements = $driver->findElements(WebDriverBy::cssSelector("#grid-search-results > ul > li > div > div > article.property-card"));
      $list = scrapeProperties($propertyElements);

      foreach ($list as $item) {
        if ($item["zpid"] && $item["link"]) {
          $detailUrl = "https://api.scrapingdog.com/scrape?api_key=$apiKey&url=" . $item["link"];
          $driver->get($detailUrl);
          sleep(5);

          $detailHtml = $driver->findElement(WebDriverBy::cssSelector("div.detail-page"));
          $result = scrapePropertyDetail($item["zpid"], $detailHtml);
          $result["zpid"] = $item["zpid"];
          $result["url"] = $item["link"];

          // insert properties to table
          $sql = "
            INSERT INTO properties
            (
              zpid,
              url,
              image,
              price,
              address,
              beds,
              baths,
              sqft,
              type,
              zestimate,
              houseType,
              builtYear,
              heating,
              cooling,
              parking,
              lot,
              priceSqft,
              agencyFee,
              days,
              views,
              saves,
              special,
              overview,
              createdAt
            )
            VALUES
            (
              '" . $db->makeSafe($result["zpid"]) . "',
              '" . $db->makeSafe($result["url"]) . "',
              '" . $db->makeSafe($result["image"]) . "',
              '" . $db->makeSafe($result["price"]) . "',
              '" . $db->makeSafe($result["address"]) . "',
              '" . $db->makeSafe($result["beds"]) . "',
              '" . $db->makeSafe($result["baths"]) . "',
              '" . $db->makeSafe($result["sqft"]) . "',
              '" . $db->makeSafe($result["type"]) . "',
              '" . $db->makeSafe($result["zestimate"]) . "',
              '" . $db->makeSafe($result["houseType"]) . "',
              '" . $db->makeSafe($result["builtYear"]) . "',
              '" . $db->makeSafe($result["heating"]) . "',
              '" . $db->makeSafe($result["cooling"]) . "',
              '" . $db->makeSafe($result["parking"]) . "',
              '" . $db->makeSafe($result["lot"]) . "',
              '" . $db->makeSafe($result["priceSqft"]) . "',
              '" . $db->makeSafe($result["agencyFee"]) . "',
              '" . $db->makeSafe($result["days"]) . "',
              '" . $db->makeSafe($result["views"]) . "',
              '" . $db->makeSafe($result["saves"]) . "',
              '" . $db->makeSafe($result["special"]) . "',
              '" . $db->makeSafe($result["overview"]) . "',
              '" . date('Y-m-d H:i:s') . "'
            )";

          $db->query($sql);

          $properties[] = $result;
        }
      }

      $currentPage++;
    }
  }
} catch (NoSuchElementException $e) {
  print_r($e);
}

echo json_encode($properties);
$driver->close();

exit();

// download images
$query = "SELECT * FROM images";
$images = $db->query("SELECT * FROM images");

if ($images) {
  if ($images->num_rows > 0) {
    while ($row = $images->fetch_assoc()) {
      try {
        $id = $row['id'];
        $zpid = $row['zpid'];
        $imgUrl = $row['url'];

        $imgFolder = __DIR__ . '/download/images/' . $zpid;
        if (!file_exists($imgFolder)) {
          mkdir($imgFolder, 0777, true);
        }

        $imgPath = $imgFolder . "/" . basename($imgUrl);
        if (!file_exists($imgPath)) {
          $imgData = file_get_contents($imgUrl);
          if ($imgData !== false) {
            file_put_contents($imgPath, $imgData);
          }
        }
      } catch (Exception $e) {
        print_r($e);
      }
    }
  }
}



foreach (STATE_LIST as $key => $state) {
  foreach (BED_VALUES as $bed) {
    foreach (BATH_VALUES as $bath) {
      foreach (SQFT_VALUES as $sqft) {
        $stateAlias = strtolower($key);

        if ($sqft["min"] === 0) {
          unset($sqft["min"]);
        }

        if ($sqft["max"] === 0) {
          unset($sqft["max"]);
        }

        $filterState = array(
          "beds" => array(
            "min" => $bed
          ),
          "baths" => array(
            "min" => $bath
          ),
          "sqft" => $sqft,
          "pmf" => array(
            "value" => true
          ),
          "sort" => array(
            "value" => "globalrelevanceex"
          ),
          "nc" => array(
            "value" => false
          ),
          "fsbo" => array(
            "value" => false
          ),
          "cmsn" => array(
            "value" => false
          ),
          "pf" => array(
            "value" => true
          ),
          "fsba" => array(
            "value" => false
          )
        );

        $query = array(
          "pagination" => new stdClass(),
          "usersSearchTerm" => $key,
          "filterState" => $filterState,
          "isListVisible" => true
        );

        $queryString = json_encode($query);
        $searchQueryState = urlencode($queryString);
        $url = "https://api.scrapingdog.com/scrape?api_key=$apiKey&url=https://www.zillow.com/$stateAlias/?searchQueryState=$searchQueryState";
        echo $url;

        $driver->get($url);

        // $driver->get('https://api.scrapingdog.com/scrape?api_key=64e4c5478d07b1208ead57b8&url=https://www.zillow.com/in/foreclosures/&dynamic=false');

        try {
          $totalCount = $driver->findElement(WebDriverBy::cssSelector("div.ListHeader__NarrowViewWrapping-srp__sc-1rsgqpl-1.idxSRv.search-subtitle span.result-count"))->getText();
          $pattern = '/\d+/';

          preg_match('/\d+/', $totalCount, $matches);

          if (isset($matches[0])) {
            $totalCount = intval($matches[0]);
            $itemsPerPage = 41;


            $currentPage = 1;
            $maxPage = ceil($totalCount / $itemsPerPage);

            while ($currentPage <= $maxPage) {
              if ($currentPage !== 1) {
                $pagination = array(
                  "currentPage" => $currentPage,
                );
                $query["pagination"] = $pagination;
              }

              $queryString = json_encode($query);
              $searchQueryState = urlencode($queryString);
              $pageUrl = "https://api.scrapingdog.com/scrape?api_key=$apiKey&url=https://www.zillow.com/$stateAlias/?searchQueryState=$searchQueryState";
              $driver->get($pageUrl);
              sleep(5);

              $html = $driver->findElement(WebDriverBy::tagName('html'));
              $html->sendKeys(WebDriverKeys::END);
              sleep(5);

              $propertyElements = $driver->findElements(WebDriverBy::cssSelector("#grid-search-results > ul > li > div > div > article.property-card"));
              if (count($propertyElements) > 0) {
                foreach ($propertyElements as $propertyElement) {
                  $zpid = str_replace("zpid_", "", $propertyElement->getAttribute("id"));
                  $zpid = intval($zpid);

                  if ($zpid) {
                    $exist = $db->query("SELECT * FROM properties WHERE zpid = $zpid");

                    if ($exist->num_rows == 0) {
                      $url = $propertyElement->findElement(WebDriverBy::cssSelector("div.property-card-data > a"))->getAttribute("href");
                      $address = $propertyElement->findElement(WebDriverBy::cssSelector("div.property-card-data > a > address"))->getText();

                      try {
                        $beds = $propertyElement->findElement(WebDriverBy::cssSelector("div.property-card-data div.StyledPropertyCardDataArea-c11n-8-84-3__sc-yipmu-0.dbDWjx > ul > li:nth-child(1) > b"))->getText();
                      } catch (NoSuchElementException $e) {
                        $beds = 0;
                      }

                      try {
                        $baths = $propertyElement->findElement(WebDriverBy::cssSelector("div.property-card-data div.StyledPropertyCardDataArea-c11n-8-84-3__sc-yipmu-0.dbDWjx > ul > li:nth-child(2) > b"))->getText();
                      } catch (NoSuchElementException $e) {
                        $baths = 0;
                      }

                      $price = $propertyElement->findElement(WebDriverBy::cssSelector("div.property-card-data span.PropertyCardWrapper__StyledPriceLine-srp__sc-16e8gqd-1"))->getText();


                      $imgList = [];
                      $imgElements = $propertyElement->findElements(WebDriverBy::cssSelector("div.StyledPropertyCardPhoto-c11n-8-84-3__sc-ormo34-0.dGCVxQ.StyledPropertyCardPhoto-srp__sc-1gxvsd7-0"));
                      foreach ($imgElements as $imgElement) {
                        $imgUrl = $imgElement->findElement(WebDriverBy::cssSelector("img.Image-c11n-8-84-3__sc-1rtmhsc-0"))->getAttribute("src");

                        $imgExist = $db->query("SELECT * FROM images WHERE zpid = $zpid AND url = '$imgUrl'");

                        if ($imgExist->num_rows == 0) {
                          $sql = "
                            INSERT INTO images
                            (
                              zpid, 
                              url,
                              createdAt
                            )
                            VALUES
                            (
                              '" . $db->makeSafe($zpid) . "',
                              '" . $db->makeSafe($imgUrl) . "',
                              '" . date('Y-m-d H:i:s') . "'
                            )";
                          $db->query($sql);
                        }

                        $imgList[] = $imgUrl;
                      }

                      $sql1 = "
                        INSERT INTO properties
                        (
                          zpid, 
                          address,
                          price,
                          beds,
                          baths,
                          images,
                          url,
                          createdAt
                        )
                        VALUES
                        (
                          '" . $db->makeSafe($zpid) . "',
                          '" . $db->makeSafe($address) . "',
                          '" . $db->makeSafe($price) . "',
                          '" . $db->makeSafe($beds) . "',
                          '" . $db->makeSafe($baths) . "',
                          '" . $db->makeSafe(json_encode($imgList)) . "',
                          '" . $db->makeSafe($url) . "',
                          '" . date('Y-m-d H:i:s') . "'
                        )";

                      $db->query($sql1);

                      $result[] = array(
                        "zpid" => $zpid,
                        "url" => $url,
                        "address" => $address,
                        "price" => $price,
                        "beds" => intval($beds),
                        "baths" => intval($baths),
                        "images" => $imgList,
                      );
                    }
                  }
                }
              }

              $currentPage++;
            }
          }
        } catch (NoSuchElementException $e) {
          print_r($e);
        }
      }
    }
  }
}

echo json_encode($result);
$driver->close();

// download images
$query = "SELECT * FROM images";
$images = $db->query("SELECT * FROM images");

if ($images) {
  if ($images->num_rows > 0) {
    while ($row = $images->fetch_assoc()) {
      try {
        $id = $row['id'];
        $zpid = $row['zpid'];
        $imgUrl = $row['url'];

        $imgFolder = __DIR__ . '/download/images/' . $zpid;
        if (!file_exists($imgFolder)) {
          mkdir($imgFolder, 0777, true);
        }

        $imgPath = $imgFolder . "/" . basename($imgUrl);
        if (!file_exists($imgPath)) {
          $imgData = file_get_contents($imgUrl);
          if ($imgData !== false) {
            file_put_contents($imgPath, $imgData);
          }
        }
      } catch (Exception $e) {
        print_r($e);
      }
    }
  }
}

exit();
