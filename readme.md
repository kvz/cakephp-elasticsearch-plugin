Elastic search [was recently used index the Firefox4 twitter stream](http://pedroalves-bi.blogspot.com/2011/03/firefox-4-twitter-and-nosql.html)
and make it searchable. It's based on Lucene and has a simple JSON based interface
that you can use to store objects and search through them (for instance even with CURL).

This also makes it easy to have your search indexes be updated in realtime
whenever your CakePHP models change data. Cause basically all we'd have to
do is do a Curl PUT, DELETE, etc to also make the change in Elastisearch
with every afterSave and afterDelete.

This plugin provides

 - a behavior to automatically update your indexes
 - a shell task to do full index fills
 - a generic search component that you can attach to your AppController and will intercept
   search actions on enabled models. Will return results in JSON format for easy
   AJAX integration.

Uses [ruflin's Elastica PHP library](https://github.com/ruflin/Elastica) to connect
to Elasticsearch.

## Installation

    cd app/plugins
    git clone git://github.com/kvz/cakephp-elasticsearch-plugin.git elasticsearch

## Integration

### Database

app/config/database.php

    class DATABASE_CONFIG {
        public $elastic = array(
            'host' => '127.0.0.1',
            'port' => '9200',
        );
        // ... etc

### Model

app/models/ticket.php (minimal example)

    public $actsAs = array(
        'Elasticsearch.Searchable' => array(
            
        ),
        // ... etc


app/models/ticket.php (full example)

    public $actsAs = array(
        'Elasticsearch.Searchable' => array(
            'debug_traces' => false,
            'searcher_enabled' => false,
            'searcher_action' => 'searcher',
            'searcher_param' => 'q',
            'searcher_serializer' => 'json_encode',
            'index_name' => 'main',
            'index_find_params' => array(
                'limit' => 1,
                'fields' => array(
                    'subject',
                    'from',
                ),
                'contain' => array(
                    'Customer',
                    'TicketResponse',
                    'TicketObjectLink(foreign_model,foreign_id)',
                    'TicketPriority(code)',
                    'TicketQueue(name)',
                ),
                'order' => array(
                    'Ticket.id' => 'DESC',
                ),
            ),
            'highlight' => array(
                'pre_tags' => array('<em class="highlight">'),
                'post_tags' => array('</em>'),
                'fields' => array(
                    'TicketResponse/0/content' => array(
                        'fragment_size' => 200,
                        'number_of_fragments' => 1,
                    ),
                ),
            ),
            'auto_update' => false,
            'error_handler' => 'php',
            'enforce' => array(
                'Customer/id' => 123,
                // callback: '#Customer/id' => array('LiveUser', 'id'),
            ),
        ),
    );

### Controller

app/app_controller.php

    public $components = array(
        'Elasticsearch.Searcher',
        // ... etc

This component will only actually fire when the Controller->modelClass
has the searchable behavior attached.

I chose for this method  (vs a dedicated SearchesController) so ACLing is easier.
e.g. You may already have an ACL for /tickets/*, so /tickets/search will automatically
be restricted the same way.

## Try it

From your shell:

    # Fill all indexes
    ./cake indexer fill

    # Fill index with tickets
    ./cake indexer fill Ticket
    
    # Try a ticket search from commandline
    ./cake indexer search Ticket Hello

From your browser

    http://www.example.com/tickets/searcher/q:*kevin*

## Todo

 - auto_update

## Useful commands

    # Get Status
    curl -XGET 'http://127.0.0.1:9200/_status?pretty=true'
    
    # Dangerous: Delete an entire index
    curl -XDELETE 'http://127.0.0.1:9200/testme'

    # Get all tickets
    curl -XGET http://127.0.0.1:9200/main/ticket/_search -d '{
        "query" : {
            "term" : { "Ticket/id": "*" }
        }
    }'