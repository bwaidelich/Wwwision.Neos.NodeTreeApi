# Wwwision.Neos.NodeTreeApi

An OpenAPI compliant HTTP API for the node trees of the Neos backend, built on
[neos/openapi-flowadapter](https://github.com/neos/openapi-flowadapter).

It explores a better API for the navigate component of the Neos UI:

- a tree per configured node tree preset (and any number of additional trees)
- **virtual items** – entries that don't represent a node, e.g. blog postings grouped by year and month
- one level at a time, with **cursor based pagination** for endless scrolling
- revealing a deeply nested node with a **single request**

## Usage

```bash
composer require wwwision/neos-nodetreeapi
```

The API is served under `/neos/node-tree-api` and authenticated through the session of the Neos backend: in a
browser that is logged in to Neos, open

- `/neos/node-tree-api/docs` for the Swagger UI, or
- `/_Resources/Static/Packages/Wwwision.Neos.NodeTreeApi/demo.html` for a demo client

### Operations

| Operation | |
| --- | --- |
| `GET /trees` | The available trees: `[{id, label, icon}]` |
| `GET /{contentRepository}/{workspace}/trees/{tree}/items` | Levels of a tree, see below |

The query parameters of `items`:

| Parameter | |
| --- | --- |
| `site` | The node name of the site, e.g. `neosdemo` (required) |
| `dimensions` | The dimension values as JSON object, e.g. `{"language":"en_US"}` |
| `parent` | The item whose children to list. Without it, the root level is returned: the site node |
| `cursor` | The `previousCursor` or `nextCursor` of a level, to get the previous/next page of the same parent |
| `limit` | The maximum number of items per level (default 50, maximum 200) |
| `expandTo` | An item to reveal: returns the root level and all levels down to the one containing the item, each a window around the respective ancestor. Can't be combined with `parent` or `cursor` |

The result is a flat list of levels (JSON schemas can't be recursive):

```json
{
  "levels": [
    {
      "parentId": "n.995c9174-ddd6-4d5c-cfc0-1ffc82184677",
      "items": [
        {
          "id": "n.d87adae6-9e61-4dbc-b596-219abe1e45a2",
          "label": "Blog",
          "icon": "newspaper",
          "hasChildren": true,
          "node": {
            "aggregateId": "d87adae6-9e61-4dbc-b596-219abe1e45a2",
            "nodeTypeName": "Neos.Demo:Document.Blog",
            "isTethered": false,
            "isDisabled": false,
            "isHiddenInMenu": false,
            "isFallback": false
          }
        },
        {
          "id": "v.blogPostingsByDate.eyJwIjoiZDg3YWRhZTYtOWU2MS00ZGJjLWI1OTYtMjE5YWJlMWU0NWEyIiwieSI6MjAyNX0",
          "label": "2025",
          "icon": "calendar-alt",
          "hasChildren": true,
          "node": null
        }
      ],
      "previousCursor": null,
      "nextCursor": "eyJyIjoiZGVmYXVsdCIsInAiOnsiYWZ0ZXIiOiI1MTdhZDc5OS0zNWRmLTQzMjQtOTQyOS01Yzc1NjI5YThiMzQifX0"
    }
  ]
}
```

Item ids and cursors are opaque to clients. Items with `node: null` are virtual. Errors are
[RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem documents: `400` for invalid input (including malformed
cursors), `401` without backend session, `403` if the workspace may not be read, `404` for unknown trees, sites or
items.

## Configuration

### Trees

One tree is derived from every `Neos.Neos.userInterface.navigateComponent.nodeTree.presets` entry. Its
`baseNodeType` filters **every level**: a preset like `Some.Package:BlogPosting` results in an empty tree, since the
site node has no children of that type. Such presets are search filters rather than trees – searching is not part of
this API yet.

Trees can be adjusted, removed (`~`) and added:

```yaml
Wwwision:
  Neos:
    NodeTreeApi:
      trees:
        'default':
          icon: 'sitemap'
        'folders':
          label: 'Some.Package:Main:tree.folders'  # a translation shorthand, or a plain label
          icon: 'folder'
          baseNodeType: 'Neos.Neos:Document,!Some.Package:Page'
```

### Resolvers and virtual items

The children of an item are listed by a resolver: the one mapped to the node type of a node, or the one that created
a virtual item. All other nodes are handled by the `default` resolver, which lists child nodes by their position.

This package comes with a `DateGroupingResolver`, which groups child nodes by a date property in virtual year and
month items. For the Neos.Demo blog (see `Configuration/Development/Settings.yaml`):

```yaml
Wwwision:
  Neos:
    NodeTreeApi:
      resolvers:
        'blogPostingsByDate':
          className: 'Wwwision\Neos\NodeTreeApi\Resolver\DateGroupingResolver'
          # passed to the constructor as named arguments
          options:
            groupedNodeType: 'Neos.Demo:Document.BlogPosting'
            property: 'datePublished'
            # granularity: 'year'
            # newestFirst: false
            # monthLabelFormat: 'LLLL y'
      trees:
        'default':
          childrenResolvers:
            'neosDemoBlog':
              nodeType: 'Neos.Demo:Document.Blog'  # includes sub types
              resolver: 'blogPostingsByDate'
              # position: 'start'                 # the first matching entry wins
```

A custom resolver implements `Wwwision\Neos\NodeTreeApi\Resolver\ChildrenResolver`:

- `resolve()` returns a page of the children of an item. Cursors are arrays the resolver defines itself, it gets them
  back for the previous/next page
- `hasChildren()` tells whether items have children, for a whole level at once
- `resolveAround()` returns the level(s) from an item down to a descendant node – including any virtual levels in
  between – for `expandTo`

Everything else it needs (subgraph, tree, node types, locale, …) is part of the `ResolverContext`, which also creates
the items: `nodeItem()` for nodes, `virtualItem()` for virtual items with a payload that is available as
`$parent->id->payload()` when their children are requested.

## Design notes and limitations

- **Authentication:** the backend session is made available to the API by a `ControllerObjectName` request pattern
  for the controller of the adapter (patterns of one provider are only OR-combined if they are of the same type). As
  a consequence, the backend session is available to *all* APIs served by the adapter. There is no CSRF protection,
  which is fine as long as the API is read-only.
- **Read access** is enforced by the content repository: nodes the user may not read are not visible, and requesting
  a workspace without read permission results in a `403` – also for workspaces that don't exist.
- **Pagination** of the default resolver is relative to siblings ("after node X"), so pages don't shift when nodes are
  added or removed while scrolling. If the node a cursor refers to is moved away, the following pages are the ones of
  its new parent.
- **`hasChildren`** costs one count query per item – the content graph can't count the children of several nodes at
  once. The resolver interface is batch-capable, so this can be optimized without changing the API.
- **Date grouping** uses the date as it is stored, i.e. in the timezone it was entered in. Grouped nodes without a date
  are not listed. Years and months are not paginated.

## Tests

```bash
bin/phpunit -c Build/BuildEssentials/PhpUnit/UnitTests.xml DistributionPackages/Wwwision.Neos.NodeTreeApi/Tests/Unit
```
