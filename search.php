#!/usr/bin/php
<?php

/**
 * search.php - Use a public web service to find property tax auctions.
 *
 * XXX TODO: Grayson County listings use Geographic ID, not Property ID.
 */


/**
 * Representation of a county.
 * This is referenced from search queries and search results.
 */
class County
{
    /**
     * County name.
     * @var string
     */
    private $name;

    /**
     * County ID as used by the web service.
     * @var string
     */
    private $id;

    /**
     * URL prefix for appraisal district property information.
     * @var string
     */
    private $url;


    /**
     * Create a new County object.
     * @param string $name  County name.
     * @param int $id  County ID.
     * @param string $url  County appraisal district URL prefix.
     */
    public function __construct($name, $id, $url = null)
    {
        $this->name = $name;
        $this->id = $id;
        $this->url = $url;
    }


    public function getName()
    {
        return $this->name;
    }


    public function getId()
    {
        return $this->id;
    }


    public function getUrl()
    {
        return $this->url;
    }
}


/**
 * A collection of all the counties we will search.
 * We only need one of these, so this will effectively be a singleton.
 */
class County_Collection
{
    /** @var County[] */
    private $counties;


    /**
     * Get counties that we loaded.
     * @return County[]
     */
    public function getCounties()
    {
        return $this->counties;
    }


    /**
     * Load a collection of counties from a CSV file.
     * @param string $filename
     */
    public function loadFromFile($filename)
    {
        $file = fopen($filename, 'r');
        if ($file === FALSE) {
            throw new Exception(
                'Unable to open file: ' . var_export($filename, true)
            );
        }

        $counties = array();
        while ($line = fgetcsv($file)) {
            $name = $line[0];
            $id = $line[1];
            if (isset($line[2])) {
                $url = $line[2];
            } else {
                $url = null;
            }
            $county = new County($name, $id, $url);
            $counties[$id] = $county;
        }
        $this->counties = $counties;

        fclose($file);
    }
}


/**
 * A query to search a single county for property tax auction listings.
 */
class Search_Query
{
    /**
     * Target URL for the property search web service.
     * @var string
     */
    private $url = 'http://actweb.acttax.com/pls/sales/property_taxsales_pkg.results_page';

    /**
     * State to search in.  We are only interested in Texas.
     * @var string
     */
    private $state = 'TX';

    /**
     * Sale type, as defined by the web service.
     * SA=sale, SO=struck-off
     * @var string
     */
    private $saleType = 'SA'; 

    /**
     * Minimum market value.
     * @var int
     */
    private $adjudgedFrom = 90000;

    /**
     * County ID, as defined by the web service.
     * @var int
     */
    private $countyId;


    /**
     * Create a new search query for the specified county.
     * @param int $countyId
     */
    public function __construct($countyId)
    {
        $this->countyId = $countyId;
    }


    /**
     * Execute the search query.
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


/**
 * A single result (a property auction listing) from the search.
 */
class Search_Result
{
    /** @var County */
    private $county;


    // These are populated from the captured data.
    /** @var string */
    private $accountNumber;

    /** @var string */
    private $adjudgedValue;

    /** @var string */
    private $minimumBid;


    /**
     * Create and initialize a new search result.
     * @param County $county  The state county this result is in.
     */
    public function __construct(County $county)
    {
        $this->county = $county;
    }


    public function getCounty()
    {
        return $this->county;
    }


    public function getAccountNumber()
    {
        return $this->accountNumber;
    }


    public function getAdjudgedValue()
    {
        return $this->adjudgedValue;
    }


    public function getMinimumBid()
    {
        return $this->minimumBid;
    }


    /**
     * Get the appraisal district URL for this property.
     * @return string
     */
    public function getUrl()
    {
        if (empty($this->county)) {
            throw new Exception(
                "Can't get URL for property; county is not set."
            );
        }
        if (empty($this->accountNumber)) {
            throw new Exception(
                "Can't get URL for property; account number is not set."
            );
        }
        return $this->county->getUrl() . $this->accountNumber;
    }


    /**
     * Get the string/text content from an XML node.
     * This is a utility method used when loading the result from XML.
     * @param SimpleXMLElement $xml The XML node.
     * @return string The text content of the XML node.
     */
    private function getTextFromXmlNode(SimpleXMLElement $xml)
    {
        $text = (string)$xml;

        // Replace UTF-8 encoded non-breaking spaces with normal spaces.
        $nbsp = html_entity_decode('&nbsp;', ENT_COMPAT, 'UTF-8');
        $text = str_replace($nbsp, ' ', $text);

        $text = trim($text);

        return $text;
    }

    /**
     * Load this search result object from an XML (HTML) document.
     * @param SimpleXMLElement $xml The XML document.
     */
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
                // XXX TODO: Error: Attribute not found
            } else {
                // XXX TODO: Error: Duplicate attribute
            }
        }

        // Parse currency values, to simplify comparisons for sorting.
        $this->adjudgedValue =
            (float)str_replace(
                array('$', ','),
                '',
                $this->adjudgedValue
            );
        $this->minimumBid =
            (float)str_replace(
                array('$', ','),
                '',
                $this->minimumBid
            );
    }


    /**
     * Compare the market values of two properties.
     * This is used as a usort() callback.
     * @param Search_Result $a
     * @param Search_Result $b
     * @return int
     */
    public function compareAdjudgedValue(Search_Result $a, Search_Result $b)
    {
        $a = round($a->adjudgedValue * 100);
        $b = round($b->adjudgedValue * 100);
        return $a - $b;
    }
}


/**
 * A collection of search results which belong to the same county.
 */
class Search_Result_Collection
{
    /** @var County */
    private $county;

    /** @var Search_Result[] */
    private $results;


    /**
     * Create and initialize this collection for the specified county.
     * @var County $county
     */
    public function __construct(County $county)
    {
        $this->county = $county;
    }


    /**
     * Parse HTML into a SimpleXMLElement.
     * @param string $html
     * @return SimpleXMLElement
     */
    private function createXmlFromHtml($html)
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        // TODO: handle errors
        return simplexml_import_dom($dom);
    }


    /**
     * Load this result collection from a string of HTML.
     * @param string $html
     */
    public function loadFromHtml($html)
    {
        $documentXml = $this->createXmlFromHtml($html);
        $resultNodes = $documentXml->xpath('//*/td[@class="repTblCell"]');
        $this->results = array();
        foreach ($resultNodes as $resultNode) {
            $result = new Search_Result($this->county);
            $result->loadFromXml($resultNode);
            $this->results[] = $result;
        }

        // Sort by adjudged value, descending (highest value first).
        usort($this->results, array('Search_Result', 'compareAdjudgedValue'));
        $this->results = array_reverse($this->results);
    }


    /**
     * Get all the results in this collection.
     * @return Search_Result[]
     */
    public function getResults()
    {
        return $this->results;
    }
}



// Load the collection of counties to search.
$counties = new County_Collection();
$counties->loadFromFile('counties/searchable_counties');

// For each county, build and execute query, parse and collect results.
$allResults = array();
foreach ($counties->getCounties() as $countyId => $county) {
    $search = new Search_Query($countyId);

    $html = $search->execute();
    $countyResults = new Search_Result_Collection($county);
    $countyResults->loadFromHtml($html);

    // Skip counties with no results.
    if (count($countyResults->getResults()) == 0) {
        continue;
    }

    // Flatten results.
    $allResults = array_merge($allResults, $countyResults->getResults());

    sleep(1);
}

foreach ($allResults as $result) {
    printf(
        "%s,%s,%.2f,%.2f,%s\n",
        $result->getCounty()->getName(),
        $result->getAccountNumber(),
        $result->getAdjudgedValue(),
        $result->getMinimumBid(),
        $result->getCounty()->getUrl() ? $result->getUrl() : ''
    );
}


// EOF
