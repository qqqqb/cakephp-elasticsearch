## What am I donig?
Elasticsearch moving forward on removing types and stay with single type for the index as it shows on schedule of removal of mapping types

> Mapping types will be completely removed in Elasticsearch 7.0.0.
[https://www.elastic.co/guide/en/elasticsearch/reference/current/removal-of-types.html#_schedule_for_removal_of_mapping_types](url)

Noticing the parent/child relations will be mapped using **[Join Field](https://www.elastic.co/guide/en/elasticsearch/reference/current/parent-join.html)**

## Points that will be changed
- [x] Always set Content-Type with Http Transport
- [x] Stability on one **Type** without extra configuration
- [x] Mandatory fields for creating **Entities** in single **Type**
- [ ] Supporting **behaviors** such as CounterCache, Timestamp and Tree
- [ ] Add relationships hasOne, hasMany and belongsTo using join field and **parent/child** relationship
- [ ] Merge joins of relationships to be like contain **nested** entities

## Composer Changes
ElasticSearch 6.* only allow content type json, so I change it to not stable branch as required for now
[https://github.com/ruflin/Elastica/tree/5.x](url)
according to [Always set Content-Type with Http Transport](https://github.com/ruflin/Elastica/pull/1302)

## Before you pull
This version is for development purpose and not stable for deploying use.

## How Can Get It
This is fork of CakePHP repository, for more details you can [find the documentation for the plugin in the Cake Book](http://book.cakephp.org/3.0/en/elasticsearch.html).
