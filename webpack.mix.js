let mix = require('laravel-mix')
let path = require('path')

mix
    .setPublicPath('dist')
    .js('resources/js/field.js', 'js')
    .vue({version: 3})
    .sass('resources/sass/field.scss', 'css')
    .alias({
        'nova-mixins': path.join(__dirname, '../work/vendor/laravel/nova/resources/js/mixins'),
        'laravel-nova-ui': path.join(__dirname, '../work/vendor/laravel/nova/resources/js/components'),
        '@': path.join(__dirname, '../work/vendor/laravel/nova/resources/js')
    })
    .webpackConfig({
        externals: {
            vue: 'Vue',
            'laravel-nova': 'LaravelNova',
        },
        output: {
            uniqueName: 'whitecube/nova-flexible-content',
        },
    });
