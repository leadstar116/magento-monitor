<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace MGS\Fbuilder\Model;

/**
 * Widget model for different purposes
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
 
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
class Widget
{
    /**
     * @var \Magento\Widget\Model\Config\Data
     */
    protected $dataStorage;

    /**
     * @var \Magento\Framework\App\Cache\Type\Config
     */
    protected $configCacheType;

    /**
     * @var \Magento\Framework\View\Asset\Repository
     */
    protected $assetRepo;

    /**
     * @var \Magento\Framework\View\Asset\Source
     */
    protected $assetSource;

    /**
     * @var \Magento\Framework\View\FileSystem
     */
    protected $viewFileSystem;

    /**
     * @var \Magento\Framework\Escaper
     */
    protected $escaper;

    /**
     * @var array
     */
    protected $widgetsArray = [];
	
	protected $_filesystem;
	
	protected $_file;

    /**
     * @var \Magento\Widget\Helper\Conditions
     */
    protected $conditionsHelper;

    /**
     * @var \Magento\Framework\Math\Random
     */
    private $mathRandom;

    /**
     * @param \Magento\Framework\Escaper $escaper
     * @param \Magento\Widget\Model\Config\Data $dataStorage
     * @param \Magento\Framework\View\Asset\Repository $assetRepo
     * @param \Magento\Framework\View\Asset\Source $assetSource
     * @param \Magento\Framework\View\FileSystem $viewFileSystem
     * @param \Magento\Widget\Helper\Conditions $conditionsHelper
     */
    public function __construct(
		\Magento\Framework\View\Element\Context $context,
        \Magento\Widget\Model\Config\Data $dataStorage,
        \Magento\Framework\View\Asset\Source $assetSource,
        \Magento\Framework\View\FileSystem $viewFileSystem,
		\Magento\Framework\Filesystem $filesystem,
		\Magento\Framework\Filesystem\Driver\File $file,
        \Magento\Widget\Helper\Conditions $conditionsHelper
    ) {
        $this->escaper = $context->getEscaper();
        $this->dataStorage = $dataStorage;
        $this->assetRepo = $context->getAssetRepository();
        $this->assetSource = $assetSource;
        $this->viewFileSystem = $viewFileSystem;
        $this->conditionsHelper = $conditionsHelper;
		$this->_urlBuilder = $context->getUrlBuilder();
		$this->_filesystem = $filesystem;
		$this->_file = $file;
    }

    /**
     * @return \Magento\Framework\Math\Random
     *
     * @deprecated
     */
    private function getMathRandom()
    {
        if ($this->mathRandom === null) {
            $this->mathRandom = \Magento\Framework\App\ObjectManager::getInstance()
                ->get('\Magento\Framework\Math\Random');
        }
        return $this->mathRandom;
    }

    /**
     * Return widget config based on its class type
     *
     * @param string $type Widget type
     * @return null|array
     * @api
     */
    public function getWidgetByClassType($type)
    {
        $widgets = $this->getWidgets();
        /** @var array $widget */
        foreach ($widgets as $widget) {
            if (isset($widget['@'])) {
                if (isset($widget['@']['type'])) {
                    if ($type === $widget['@']['type']) {
                        return $widget;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Return widget XML configuration as \Magento\Framework\DataObject and makes some data preparations
     *
     * @param string $type Widget type
     * @return null|\Magento\Framework\Simplexml\Element
     */
    public function getConfigAsXml($type)
    {
        return $this->getXmlElementByType($type);
    }

    /**
     * Return widget XML configuration as \Magento\Framework\DataObject and makes some data preparations
     *
     * @param string $type Widget type
     * @return \Magento\Framework\DataObject
     */
    public function getConfigAsObject($type)
    {
        $widget = $this->getWidgetByClassType($type);

        $object = new \Magento\Framework\DataObject();
        if ($widget === null) {
            return $object;
        }
        $widget = $this->getAsCanonicalArray($widget);

        // Save all nodes to object data
        $object->setType($type);
        $object->setData($widget);

        // Correct widget parameters and convert its data to objects
        $newParams = $this->prepareWidgetParameters($object);
        $object->setData('parameters', $newParams);

        return $object;
    }

    /**
     * Prepare widget parameters
     *
     * @param \Magento\Framework\DataObject $object
     * @return array
     */
    protected function prepareWidgetParameters(\Magento\Framework\DataObject $object)
    {
        $params = $object->getData('parameters');
        $newParams = [];
        if (is_array($params)) {
            $sortOrder = 0;
            foreach ($params as $key => $data) {
                if (is_array($data)) {
                    $data = $this->prepareDropDownValues($data, $key, $sortOrder);
                    $data = $this->prepareHelperBlock($data);

                    $newParams[$key] = new \Magento\Framework\DataObject($data);
                    $sortOrder++;
                }
            }
        }
        uasort($newParams, [$this, 'sortParameters']);

        return $newParams;
    }

    /**
     * Prepare drop-down values
     *
     * @param array $data
     * @param string $key
     * @param int $sortOrder
     * @return array
     */
    protected function prepareDropDownValues(array $data, $key, $sortOrder)
    {
        $data['key'] = $key;
        $data['sort_order'] = isset($data['sort_order']) ? (int)$data['sort_order'] : $sortOrder;

        $values = [];
        if (isset($data['values']) && is_array($data['values'])) {
            foreach ($data['values'] as $value) {
                if (isset($value['label']) && isset($value['value'])) {
                    $values[] = $value;
                }
            }
        }
        $data['values'] = $values;

        return $data;
    }

    /**
     * Prepare helper block
     *
     * @param array $data
     * @return array
     */
    protected function prepareHelperBlock(array $data)
    {
        if (isset($data['helper_block'])) {
            $helper = new \Magento\Framework\DataObject();
            if (isset($data['helper_block']['data']) && is_array($data['helper_block']['data'])) {
                $helper->addData($data['helper_block']['data']);
            }
            if (isset($data['helper_block']['type'])) {
                $helper->setType($data['helper_block']['type']);
            }
            $data['helper_block'] = $helper;
        }

        return $data;
    }

    /**
     * Return filtered list of widgets
     *
     * @param array $filters Key-value array of filters for widget node properties
     * @return array
     * @api
     */
    public function getWidgets($filters = [])
    {
        $widgets = $this->dataStorage->get();
        $result = $widgets;

        // filter widgets by params
        if (is_array($filters) && count($filters) > 0) {
            foreach ($widgets as $code => $widget) {
                try {
                    foreach ($filters as $field => $value) {
                        if (!isset($widget[$field]) || (string)$widget[$field] != $value) {
                            throw new \Exception();
                        }
                    }
                } catch (\Exception $e) {
                    unset($result[$code]);
                    continue;
                }
            }
        }

        return $result;
    }

    /**
     * Return list of widgets as array
     *
     * @param array $filters Key-value array of filters for widget node properties
     * @return array
     * @api
     */
    public function getWidgetsArray($filters = [])
    {
        if (empty($this->widgetsArray)) {
            $result = [];
            foreach ($this->getWidgets($filters) as $code => $widget) {
                $result[$widget['name']] = [
                    'name' => __((string)$widget['name']),
                    'code' => $code,
                    'type' => $widget['@']['type'],
                    'description' => __((string)$widget['description']),
                ];
            }
            usort($result, [$this, "sortWidgets"]);
            $this->widgetsArray = $result;
        }
        return $this->widgetsArray;
    }

    /**
     * Return widget presentation code in WYSIWYG editor
     *
     * @param string $type Widget Type
     * @param array $params Pre-configured Widget Params
     * @param bool $asIs Return result as widget directive(true) or as placeholder image(false)
     * @return string Widget directive ready to parse
     * @api
     */
    public function getWidgetDeclaration($type, $params = [], $asIs = true)
    {
        $directive = '{{widget type="' . $type . '"';

        foreach ($params as $name => $value) {
            // Retrieve default option value if pre-configured
            if ($name == 'conditions') {
                $name = 'conditions_encoded';
                $value = $this->conditionsHelper->encode($value);
            } elseif (is_array($value)) {
                $value = implode(',', $value);
            } elseif (trim($value) == '') {
                $widget = $this->getConfigAsObject($type);
                $parameters = $widget->getParameters();
                if (isset($parameters[$name]) && is_object($parameters[$name])) {
                    $value = $parameters[$name]->getValue();
                }
            }
            if ($value) {
                $directive .= sprintf(' %s="%s"', $name, $value);
            }
        }

        $directive .= $this->getWidgetPageVarName($params);

        $directive .= '}}';

        if ($asIs) {
            return $directive;
        }

        $html = sprintf(
            '<img id="%s" src="%s" title="%s">',
            $this->idEncode($directive),
            $this->getPlaceholderImageUrl($type),
            $this->escaper->escapeUrl($directive)
        );
        return $html;
    }

    /**
     * @param array $params
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getWidgetPageVarName($params = [])
    {
        $pageVarName = '';
        if (array_key_exists('show_pager', $params) && (bool)$params['show_pager']) {
            $pageVarName = sprintf(
                ' %s="%s"',
                'page_var_name',
                'p' . $this->getMathRandom()->getRandomString(5, \Magento\Framework\Math\Random::CHARS_LOWERS)
            );
        }
        return $pageVarName;
    }

    /**
     * Get image URL of WYSIWYG placeholder image
     *
     * @param string $type
     * @return string
     */
    public function getPlaceholderImageUrl($type)
    {
        $placeholder = false;
        $widget = $this->getWidgetByClassType($type);
        if (is_array($widget) && isset($widget['placeholder_image'])) {
            $placeholder = (string)$widget['placeholder_image'];
        }
		
        if ($placeholder) {
			$arrPath = explode('::',$placeholder);
			
			if(count($arrPath)==2){
				$widgetPath = str_replace('::','/',$placeholder);
				$adminStaticUrl = $this->_urlBuilder->getBaseUrl(['_type' => \Magento\Framework\UrlInterface::URL_TYPE_STATIC]).'adminhtml/Magento/backend/en_US/';
				
				$filePath = $this->_filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath('adminhtml/Magento/backend/en_US/').$widgetPath;
				if ($this->_file->isExists($filePath))  {
					return $adminStaticUrl.$widgetPath;
				}
			}
        }
        return $this->assetRepo->getUrl('MGS_Mpanel::images/widget/placeholder.gif');
    }

    /**
     * Get a list of URLs of WYSIWYG placeholder images
     *
     * Returns array(<type> => <url>)
     *
     * @return array
     */
    public function getPlaceholderImageUrls()
    {
        $result = [];
        $widgets = $this->getWidgets();
        /** @var array $widget */
        foreach ($widgets as $widget) {
            if (isset($widget['@'])) {
                if (isset($widget['@']['type'])) {
                    $type = $widget['@']['type'];
                    $result[$type] = $this->getPlaceholderImageUrl($type);
                }
            }
        }
        return $result;
    }

    /**
     * Remove attributes from widget array and emulate work of \Magento\Framework\Simplexml\Element::asCanonicalArray
     *
     * @param array $inputArray
     * @return array
     */
    protected function getAsCanonicalArray($inputArray)
    {
        if (array_key_exists('@', $inputArray)) {
            unset($inputArray['@']);
        }
        foreach ($inputArray as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $inputArray[$key] = $this->getAsCanonicalArray($value);
        }
        return $inputArray;
    }

    /**
     * Encode string to valid HTML id element, based on base64 encoding
     *
     * @param string $string
     * @return string
     */
    protected function idEncode($string)
    {
        return strtr(base64_encode($string), '+/=', ':_-');
    }

    /**
     * User-defined widgets sorting by Name
     *
     * @param array $firstElement
     * @param array $secondElement
     * @return bool
     */
    protected function sortWidgets($firstElement, $secondElement)
    {
        return strcmp($firstElement["name"], $secondElement["name"]);
    }

    /**
     * Widget parameters sort callback
     *
     * @param \Magento\Framework\DataObject $firstElement
     * @param \Magento\Framework\DataObject $secondElement
     * @return int
     */
    protected function sortParameters($firstElement, $secondElement)
    {
        $aOrder = (int)$firstElement->getData('sort_order');
        $bOrder = (int)$secondElement->getData('sort_order');
        return $aOrder < $bOrder ? -1 : ($aOrder > $bOrder ? 1 : 0);
    }
}
