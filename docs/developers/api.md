
## API

The API is an important fixture of the plugin that provides it's functionality. It is

### Exporting

You can find the export API endpoint at `api/product-variants/export/{id}`. 

The endpoint expects a product ID to be passed (replacing `{id}`) and supports the following query parameters.

#### Format Parameter

The format parameter is used to change the format of the data output (i.e. as a CSV, XML, JSON, YAML and et cetera).

You can pass a format parameter using the following: `format={type}`.

Currently the supported types are **csv** and **json** as a string. The default value if no parameter is passed is "json".

##### Example

###### Input

`api/product-variants/export/6000?format=csv`

###### Output

```
SKU, Stock, Price, Height, Width, Length, Weight, OptionA, OptionB, OptionC
6060AA0A, 55, 100.55, 100, 60, 80, 5, 20, 80, 200
6060AC00, 500, 144.95, 0, 0, 0, 0, 100, 200, 500
```

#### Download parameter

The download parameter is used to output the results to a downloadable file, based on the format. If the format is a CSV, the browser will download a file with the products given name and a CSV extention.

You can pass a format parameter using the following: `download={value}`

The supported values are boolean, either truthy (**true** or **1**) or falsey (**false** or **0**).

#### Filter attributes parameter

Filter out variants that don't include the given criteria.

Use a `POST` request with a body like 

```json
{
  "conditions": {
    "Attribute Name": "Value A",
    "Another Attribute": "Value B"
  }
}
```

#### Output

```
SKU, Stock, Price, Height, Width, Length, Weight, OptionA, OptionB, OptionC
6060AA0A, 55, 100.55, 100, 60, 80, 5, 20, 100, 200
6060AC00, 500, 144.95, 0, 0, 0, 0, 20, 100, 500
```
