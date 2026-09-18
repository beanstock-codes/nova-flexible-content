let mix = require('laravel-mix')
let path = require('path')

const novaPath = process.env.NOVA_PATH || path.join(__dirname, 'vendor/laravel/nova')

mix
    .setPublicPath('dist')
    .js('resources/js/field.js', 'js')
    .vue({ version: 3 })
    .sass('resources/sass/field.scss', 'css')
    .webpackConfig({
        externals: {
            vue: 'Vue',
            'laravel-nova-ui': 'LaravelNovaUi',
        },
        resolve: {
            alias: {
                'laravel-nova': path.join(novaPath, 'resources/js/mixins/packages.js'),
                '@': path.join(novaPath, 'resources/js'),
            },
        },
        output: {
            uniqueName: 'whitecube/nova-flexible-content',
        },
    })
