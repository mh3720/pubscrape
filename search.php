#!/usr/bin/php
<?php

// XXX TODO: Grayson County listings use Geographic ID, not Property ID

function compareAdjudgedValue(Search_Result $a, Search_Result $b)
{
    $a = round($a->adjudgedValue * 100);
    $b = round($b->adjudgedValue * 100);
    return $a - $b;
}

class Search_Result
{
    public $county;
    public $accountNumber;
    public $adjudgedValue;
    public $minimumBid;
    public $url;

    public function getTextFromXmlNode(SimpleXMLElement $xml)
    {
        $text = (string)$xml;

        // Replace UTF-8 encoded non-breaking spaces with normal spaces.
        $nbsp = html_entity_decode('&nbsp;', ENT_COMPAT, 'UTF-8');
        $text = str_replace($nbsp, ' ', $text);

        $text = trim($text);

        return $text;
    }

    public function loadFromXml(SimpleXMLElement $xml)
    {
        $attributes = array(
            // attributeName => attributeText
            'accountNumber' => 'Account Number',
            'adjudgedValue' => 'Adjudged Value',
            'minimumBid' => 'Estimated Minimum Bid',
        );

        foreach ($attributes as $attributeName => $attributeText) {
            // Find the <td> node for this attribute.
            // It is important that we use a relative path here.
            $xpath =
                './/td[@class="repText"]' .
                '/span[contains(.,"' . $attributeText . '")]/..';
            $tdNodes = $xml->xpath($xpath);

            if (count($tdNodes) == 1) {
                // We found the attribute.  Save it in this result object.
                $tdNode = $tdNodes[0];
                $attributeValue = $this->getTextFromXmlNode($tdNode);
                $this->{$attributeName} = $attributeValue;
            } elseif (count($tdNodes) == 0) {
                // Error: Attribute not found
            } else {
                // Error: Duplicate attribute
            }
        }

        $this->url =
            $this->county->countyAppraisalDistrictUrl .
            $this->accountNumber;
    }
}


class Search_Result_Collection
{
    public $county;
    public $results;

    public function createXmlFromHtml($html)
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        // TODO: handle errors
        return simplexml_import_dom($dom);
    }

    public function loadFromHtml($html)
    {
        $documentXml = $this->createXmlFromHtml($html);
        $resultNodes = $documentXml->xpath('//*/td[@class="repTblCell"]');
        $this->results = array();
        foreach ($resultNodes as $resultNode) {
            $result = new Search_Result();
            $result->county = $this->county;
            $result->loadFromXml($resultNode);
            $this->results[] = $result;
        }
    }
}


class Search_Query
{
    public $state = 'TX';
    public $countyId;
    public $saleType = 'SA'; // SA=sale, SO=struck-off
    public $adjudgedFrom = 90000;

    private $url = 'http://actweb.acttax.com/pls/sales/property_taxsales_pkg.results_page';

    /**
     * @return string Results/response from the search.
     */
    public function execute()
    {
        $args = array(
            'pi_state' => $this->state,
            'pi_sale_type' => $this->saleType,
            'pi_venue_group_id' => $this->countyId,
            'pi_adjudged_from' => $this->adjudgedFrom,
        );
        $argsString = http_build_query($args);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->url);

        // application/x-www-form-urlencoded
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $argsString);

        // Return response as a string
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($curl);
        if ($response === false) {
            throw new Exception("cURL HTTP request failed.");
        }

        curl_close($curl);

        return $response;
    }
}


class County
{
    public $countyId;

    public $countyName;

    // search property by account number/ID
    public $countyAppraisalDistrictUrl;
}


class County_Collection
{
    public $counties;

    function loadFromFile($filename)
    {
        $file = fopen($filename, 'r');
        if ($file === FALSE) {
            throw new Exception(
                'Unable to open file: ' . var_export($filename, true)
            );
        }

        $csvColumnMap = array(
            'countyName' => 0,
            'countyId' => 1,
            'countyAppraisalDistrictUrl' => 2,
        );

        $counties = array();
        while ($line = fgetcsv($file)) {
            $county = new County();
            foreach ($csvColumnMap as $columnName => $columnIndex) {
                if (isset($line[$columnIndex])) {
                    $county->{$columnName} = $line[$columnIndex];
                }
            }
            $counties[$county->countyId] = $county;
        }

        fclose($file);

        $this->counties = $counties;
    }
}

$counties = new County_Collection();
$counties->loadFromFile('counties/searchable_counties');
$allResults = array();
foreach ($counties->counties as $countyId => $county) {
    $search = new Search_Query();
    $search->countyId = $countyId;

    $html = $search->execute();
    $countyResults = new Search_Result_Collection();
    $countyResults->county = $county;
    $countyResults->loadFromHtml($html);

    // Skip empty results.
    if (count((array)$countyResults->results) == 0) {
        continue;
    }

    // Unformat currency.
    foreach ($countyResults->results as $property) {
        $property->adjudgedValue =
            (float)str_replace(
                array('$', ','),
                '',
                $property->adjudgedValue
            );
        $property->minimumBid =
            (float)str_replace(
                array('$', ','),
                '',
                $property->minimumBid
            );
    }
    // Sort by adjudged value.
    usort($countyResults->results, 'compareAdjudgedValue');
    $countyResults->results = array_reverse($countyResults->results);

    // Flatten results.
    $allResults = array_merge($allResults, $countyResults->results);

    sleep(1);
}

foreach ($allResults as $result) {
    printf(
        "%s,%s,%.2f,%.2f,%s\n",
        $result->county->countyName,
        $result->accountNumber,
        $result->adjudgedValue,
        $result->minimumBid,
        $result->county->countyAppraisalDistrictUrl ? $result->url : ''
    );
}


// EOF