<?php

namespace OroCrRM\Bundle\SalesBundle\Tests\Functional;

use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\TestFrameworkBundle\Test\ToolsAPI;
use Oro\Bundle\TestFrameworkBundle\Test\Client;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @outputBuffering enabled
 * @db_isolation
 */
class ControllersTest extends WebTestCase
{
    /**
     * @var Client
     */
    protected $client;

    public function setUp()
    {
        $this->client = static::createClient(
            array(),
            array_merge(ToolsAPI::generateBasicHeader(), array('HTTP_X-CSRF-Header' => 1))
        );
    }

    public function testIndex()
    {
        $this->client->request('GET', $this->client->generate('orocrm_sales_lead_index'));
        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200, 'text/html; charset=UTF-8');
    }

    public function testCreate()
    {
        $crawler = $this->client->request('GET', $this->client->generate('orocrm_sales_lead_create'));
        /** @var Form $form */
        $form = $crawler->selectButton('Save and Close')->form();
        $topic = 'topic' . ToolsAPI::generateRandomString();
        $form['orocrm_sales_lead_form[status]']              = 'new';
        $form['orocrm_sales_lead_form[topic]']               = $topic;
        $form['orocrm_sales_lead_form[firstName]']           = 'firstName';
        $form['orocrm_sales_lead_form[lastName]']            = 'lastName';
        $form['orocrm_sales_lead_form[address][city]']       = 'City Name';
        $form['orocrm_sales_lead_form[address][label]']      = 'Main Address';
        $form['orocrm_sales_lead_form[address][postalCode]'] = '10000';
        $form['orocrm_sales_lead_form[address][street2]']    = 'Second Street';
        $form['orocrm_sales_lead_form[address][street]']     = 'Main Street';
        $form['orocrm_sales_lead_form[companyName]']         = 'Company';
        $form['orocrm_sales_lead_form[email]']               = 'test@example.test';

        $doc = new \DOMDocument("1.0");
        $doc->loadHTML(
            '<select name="orocrm_sales_lead_form[address][country]" id="orocrm_sales_lead_form_address_country" ' .
            'tabindex="-1" class="select2-offscreen"> ' .
            '<option value="" selected="selected"></option> ' .
            '<option value="US">United States</option> </select>'
        );
        $field = new ChoiceFormField($doc->getElementsByTagName('select')->item(0));
        $form->set($field);
        $doc->loadHTML(
            '<select name="orocrm_sales_lead_form[address][state]" id="orocrm_sales_lead_form_address_state" ' .
            'tabindex="-1" class="select2-offscreen"> ' .
            '<option value="" selected="selected"></option> ' .
            '<option value="US.CA">California</option> </select>'
        );
        $field = new ChoiceFormField($doc->getElementsByTagName('select')->item(0));
        $form->set($field);

        $form['orocrm_sales_lead_form[address][country]'] = 'US';
        $form['orocrm_sales_lead_form[address][state]'] = 'US.CA';

        $this->client->followRedirects(true);
        $crawler = $this->client->submit($form);

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200, 'text/html; charset=UTF-8');
        $this->assertContains("Lead successfully saved", $crawler->html());

        return $topic;
    }

    /**
     * @param $topic
     * @depends testCreate
     *
     * @return string
     */
    public function testUpdate($topic)
    {
        $this->client->request(
            'GET',
            $this->client->generate('orocrm_sales_lead_index', array('_format' =>'json')),
            array(
                'leads[_filter][topic][type]=3' => '3',
                'leads[_filter][topic][value]' => $topic,
                'leads[_pager][_page]' => '1',
                'leads[_pager][_per_page]' => '10',
                'leads[_sort_by][first_name]' => 'ASC',
                'leads[_sort_by][last_name]' => 'ASC',
            )
        );

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200);

        $result = ToolsAPI::jsonToArray($result->getContent());
        $result = reset($result['data']);

        $crawler = $this->client->request(
            'GET',
            $this->client->generate('orocrm_sales_lead_update', array('id' => $result['id']))
        );

        /** @var Form $form */
        $form = $crawler->selectButton('Save and Close')->form();
        $topic = 'topic' . ToolsAPI::generateRandomString();
        $form['orocrm_sales_lead_form[topic]'] = $topic;

        $this->client->followRedirects(true);
        $crawler = $this->client->submit($form);

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200, 'text/html; charset=UTF-8');
        $this->assertContains("Lead successfully saved", $crawler->html());

        return $topic;
    }

    /**
     * @param $topic
     * @depends testUpdate
     *
     * @return string
     */
    public function testView($topic)
    {
        $this->client->request(
            'GET',
            $this->client->generate('orocrm_sales_lead_index', array('_format' =>'json')),
            array(
                'leads[_filter][topic][type]=3' => '3',
                'leads[_filter][topic][value]' => $topic,
                'leads[_pager][_page]' => '1',
                'leads[_pager][_per_page]' => '10',
                'leads[_sort_by][first_name]' => 'ASC',
                'leads[_sort_by][last_name]' => 'ASC',
            )
        );

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200);

        $result = ToolsAPI::jsonToArray($result->getContent());
        $result = reset($result['data']);

        $crawler = $this->client->request(
            'GET',
            $this->client->generate('orocrm_sales_lead_view', array('id' => $result['id']))
        );

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200, 'text/html; charset=UTF-8');
        $this->assertContains("{$topic} - Leads - Sales", $crawler->html());
    }

    /**
     * @param $topic
     * @depends testUpdate
     *
     * @return string
     */
    public function testInfo($topic)
    {
        $this->client->request(
            'GET',
            $this->client->generate('orocrm_sales_lead_index', array('_format' =>'json')),
            array(
                'leads[_filter][topic][type]=3' => '3',
                'leads[_filter][topic][value]' => $topic,
                'leads[_pager][_page]' => '1',
                'leads[_pager][_per_page]' => '10',
                'leads[_sort_by][first_name]' => 'ASC',
                'leads[_sort_by][last_name]' => 'ASC',
            )
        );

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200);

        $result = ToolsAPI::jsonToArray($result->getContent());
        $expectedResult = reset($result['data']);

        $crawler = $this->client->request(
            'GET',
            $this->client->generate(
                'orocrm_sales_lead_info',
                array('id' => $expectedResult['id'], '_widgetContainer' => 'block')
            )
        );

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200, 'text/html; charset=UTF-8');
        $this->assertContains($expectedResult['first_name'], $crawler->html());
        $this->assertContains($expectedResult['last_name'], $crawler->html());
    }

    /**
     * @param $topic
     * @depends testUpdate
     */
    public function testDelete($topic)
    {
        $this->client->request(
            'GET',
            $this->client->generate('orocrm_sales_lead_index', array('_format' =>'json')),
            array(
                'leads[_filter][topic][type]=3' => '3',
                'leads[_filter][topic][value]' => $topic,
                'leads[_pager][_page]' => '1',
                'leads[_pager][_per_page]' => '10',
                'leads[_sort_by][first_name]' => 'ASC',
                'leads[_sort_by][last_name]' => 'ASC',
            )
        );

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200);

        $result = ToolsAPI::jsonToArray($result->getContent());
        $result = reset($result['data']);

        $this->client->request(
            'DELETE',
            $this->client->generate('oro_api_delete_lead', array('id' => $result['id']))
        );

        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 204);
    }
}