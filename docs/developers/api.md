
## API

The API is an important fixture of the plugin that provides it's functionality. It is

### Exporting `api/product-variants/export`

You can find the export API endpoint at `api/product-variants/export/{id}`. 

The endpoint expects a product ID to be passed (replacing `{id}`) and supports the following query parameters.

#### Format Parameter `format`

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

#### Download Parameter `download`

The download parameter is used to output the results to a downloadable file, based on the format. If the format is a CSV, the browser will download a file with the products given name and a CSV extention.

You can pass a format parameter using the following: `download={value}`

The supported values are boolean, either truthy (**true** or **1**) or falsey (**false** or **0**).

#### Filter Options Parameter `filter-option`

The filter option parameter is used to filter out variants that don't meet the given option criteria.

You can passa a filter-option parameter using the following `filter-option[]={optionName}%3D{optionValue}`. Note that it respects multiple parameters being passed and the name and value are separated by an encoded operator (equal sign).

#### Input 

`filter-option[]=OptionA%3D20&filter-option[]=OptionB%3D100`

#### Output

```
SKU, Stock, Price, Height, Width, Length, Weight, OptionA, OptionB, OptionC
6060AA0A, 55, 100.55, 100, 60, 80, 5, 20, 100, 200
6060AC00, 500, 144.95, 0, 0, 0, 0, 20, 100, 500
```
