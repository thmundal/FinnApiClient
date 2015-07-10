<?php

namespace Finn\FinnClient;
use Finn\RestClient\CurlClient;
use Finn\RestClient\ClientInterface;

class FinnClient
{
	private $restClient = null;
	private $apiUrl = "https://cache.api.finn.no/iad/";
	
	/*
	*	Constructor
	*	@param $restClient: A restclient implementing ClientInterface 
	*/
	public function __construct(ClientInterface $restClient)
	{
		$this->restClient = $restClient;
	}
	
	/*
	*	Do a search for properties
	*	@param $type: finn realestate type "realestate-homes"
	*	@param $queryParams: Array with query parameters
	*   @return Resultset
	*/
	public function search($type, $queryParams)
	{
		$url = $this->apiUrl.'search/'.$type.'?'.http_build_query($queryParams);
		$rawData = $this->restClient->send($url);
		//parse dataene til array med objekter
		$resultSet = $this->parseResultset($rawData);
		return $resultSet;
	}
	
	/*
	*	Get single object with finncode
	*   @param $type: finn realestate type "realestate-homes"
	*	@param $finncode: The ads finncode
	*/
	public function getObject($type, $finncode)
	{
		$url = $this->apiUrl.'ad/'.$type.'/'.$finncode;
		$rawData = $this->restClient->send($url);
		$this->rawData = $rawData;
        
		//parse dataene til array med objekter
		if(isset($rawData)){
			$entry = new \SimpleXMLElement($rawData);
			$ns = $entry->getNamespaces(true);
	
			$resultSet = $this->parseEntry($entry, $ns);
			return $resultSet;
		}
	}
	
	/*
	*
	*
	*/
	private function parseEntry($entry, $ns)
	{		
		$property = new Property();
			
        $property->id = (string)$entry->children($ns['dc'])->identifier;
        $property->title = (string)$entry->title;
        $property->updated = (string)$entry->updated;
        $property->published = (string)$entry->published;
      
        $links = array();
        foreach ($entry->link as $link) {
            $rel = $link->attributes()->rel;
            $ref = $link->attributes()->href;
            $links["$rel"] = "$ref";
        }
        $property->links = $links;
      
        $isPrivate = "false";
        $status = "";
        $adType = "";
        foreach ($entry->category as $category) {
          if ($category->attributes()->scheme =="urn:finn:ad:private"){
            $isPrivate = $category->attributes()->term;
          }
          //if disposed == true, show the label
          if ($category->attributes()->scheme =="urn:finn:ad:disposed"){
            if($category->attributes()->term == "true"){
              $status = $category->attributes()->label;
            }
          }
          if ($category->attributes()->scheme =="urn:finn:ad:type"){
            $adType = $category->attributes()->label;
          }
        }
        
        $property->isPrivate = (string)$isPrivate;
        $property->status = (string)$status;
        $property->adType = (string)$adType;
        
        $property->georss = (string)$entry->children($ns['georss'])->point;
        $location = $entry->children($ns['finn'])->location;
        $property->city = (string)$location->children($ns['finn'])->city;
        $property->address = (string)$location->children($ns['finn'])->address;
        $property->postalCode = (string)$location->children($ns['finn'])->{'postal-code'};
        
        $contacts = array();
        $work = null;
        $mobile = null;
        $fax = null;
        foreach($entry->children($ns['finn'])->contact as $contact) {
            $name = (string) $contact->children()->name;
            $title = (string) $contact->attributes()->title;
            foreach($contact->{'phone-number'} as $numbers) {
                switch($numbers->attributes()) {
                    case 'work':
                        $work = (string) $numbers;
                        break;
                    case 'mobile':
                        $mobile = (string) $numbers;
                        break;
                    case 'fax':
                        $fax = (string) $numbers;
                        break;
                }
            }
            array_push($contacts, array(
                'name' => $name,
                'title' => $title,
                'work' => $work,
                'mobile' => $mobile,
                'fax' => $fax
            ));
        }
        $property->contacts = $contacts;
        
        $img = array();
        if ($entry->children($ns['media']) && $entry->children($ns['media'])->content->attributes()) {
            //$img = $entry->children($ns['media'])->content->attributes();
            foreach($entry->children($ns['media'])->content as $content) {
                $img[] = current($content->attributes());
            }
        }
        $property->img = $img;
    
        $property->author = (string)$entry->author->name;
        $adata = $entry->children($ns['finn'])->adata;
    
        $property->price = [];
        foreach ($adata->children($ns['finn'])->price as $price) {
            $out = (string)$price->attributes()->value;
            if ($price->attributes()->name == 'main') {
                $mainPrice = (string)$price->attributes()->value;
                $mainPriceFrom = (string)$price->attributes()->from;
                $mainPriceTo = (string)$price->attributes()->to;
                
                $out = ["price" => $mainPrice, "from" => $mainPriceFrom, "to" => $mainPriceTo];
            }
            $property->price[(string)$price->attributes()->name] = $out;
        }
        
        // Do a propert recursive parse of the XML tree
        // TODO: Move this up the chain so we dont need the hardcoded stuff above here
        function traverseChildTree($parent, $out) {
            global $ns;
            if($parent->count() > 0) {
                foreach($parent as $field) {
                    $attributes = $field->attributes();
                    $fields = $field->field;
                        
                    // If there are no attributes, skip to next entry
                    if(empty($attributes)) continue;
                    
                    // IF the field has no attribute value, but instead a list of children containing the values (finn:value)
                    if(!$attributes->value OR is_null($attributes->value)) {
                        if($field->count() > 0) {
                            $out->{$attributes->name} = [];
                            foreach($field->value as $v) {
                                $out->{(string)$attributes->name}[] = (string)$v;
                            }
                        }
                    } else {
                        // If the field has attribute values, then go ahead and set those
                        // Set the property to the value ($out context)
                        $out->{(string)$attributes->name} = (string)$attributes->value;
                    }
                    
                    // Check if there are children of this node and then loop trough those recursively
                    if($field->children($ns['finn'])->field->count() > 0) {
                        $inner = new Property();
                        traverseChildTree($field->children($ns['finn'])->field, $inner);
                        $out->children = $inner;
                    }
                }
            }
        }
        
        traverseChildTree($adata->children($ns['finn'])->field, $property);
        return $property;
	}
	
	//Returns an array of objects
	private function parseResultset($rawData)
    {
		$resultset = new Resultset();
		
		//parse the xml and get namespaces (needed later to extract attributes and values)
		$xmlData = new \SimpleXMLElement($rawData);
		$ns = $xmlData->getNamespaces(true);
		
		//search data:
		$resultset->title = (string)$xmlData->title;
		$resultset->subtitle = (string)$xmlData->subtitle;
		//$resultset->totalResults = (string)$xmlData->children($ns['os'])->totalResults;
		
		//navigation links
		$links = array();
		foreach ($xmlData->link as $link) {
			$rel = $link->attributes()->rel;
			$ref = $link->attributes()->href;
			$links["$rel"] = "$ref";
		}
		$resultset->links = $links;		
		//entry data
		
		//get each entry for simpler syntax when looping through them later
		$entries = array();
		foreach ($xmlData->entry as $entry) {
			array_push($entries, $entry);
		}
		
		$propertyList = array();		
		foreach ($entries as $entry) {	
			$property = $this->parseEntry($entry, $ns);
			$propertyList[] = $property;
		}
		
		$resultset->results = $propertyList;
		
		return $resultset;
	}
	

}

?>
