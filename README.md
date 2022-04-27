#CTRL Client Package

##Installation instructions

Add this code to your `composer.json` file:

```
"repositories": [    
    {
        "type": "git",
        "url": "https://github.com/phnxdgtl/ctrl-client"
    }
]
```

You can then install the libraries via:

`composer install phnxdgtl/ctrl-client`

##S3 buckets

Each site requires its own S3 bucket. These can be configured as Linode Object Storage.

Once a key has been generated, add the following lines to `app/config/filesystems.php`:

```
ctrl' => [
    'driver'   => 's3',
    'key'      => env('LINODE_STORAGE_ACCESS_KEY'),
    'secret'   => env('LINODE_STORAGE_SECRET_ACCESS_KEY'),
    'endpoint' => env('LINODE_STORAGE_ENDPOINT'),
    'region'   => env('LINODE_STORAGE_REGION'),
    'bucket'   => env('LINODE_STORAGE_BUCKET'),
],
```

You'll also need the following in `.env`; the default keys are shown here:

```
LINODE_STORAGE_ACCESS_KEY=BPFQN7ALXK1F3AQV1SQR
LINODE_STORAGE_SECRET_ACCESS_KEY=dsoJpJez2AIF75SJCwk5sh3HtTMKEgFLsCfRrzQF
LINODE_STORAGE_ENDPOINT=https://eu-central-1.linodeobjects.com
LINODE_STORAGE_REGION=eu-central-1
LINODE_STORAGE_BUCKET=********
```

##Images

I like [Laravel Thumbnail](https://github.com/rolandstarke/laravel-thumbnail) for this.

`composer require rolandstarke/laravel-thumbnail`

You can then call the following to render an image from CTRL:

`Thumbnail::src(Storage::disk('ctrl')->url(*$object->image*))->smartcrop(200, 200)->url();`

##Typesense

Add the following to `.env` to use the default Typesense account:

```
TYPESENSE_HOST=1yhk982netu73cplp-1.a1.typesense.net
TYPESENSE_KEY=kUp6Gg9CEzlR0dsdGxx87rMf1yJNYxVM
```

##Setting up the site in CTRL

Log into the CTRL Server, and add the site. That's it.
