const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const TerserPlugin = require('terser-webpack-plugin');

module.exports = (env, argv) => {
    const isProduction = argv.mode === 'production';

    return {
        entry: {
            'json-lines-importer-admin': './assets/json-lines-importer-admin.js',
        },
        output: {
            // Served by the Thelia asset resolver (BaseHook::addJS/addCSS), no public path needed
            path: path.resolve(__dirname, '../templates/backOffice/default-twig/assets/dist'),
            filename: '[name].js',
            clean: true,
        },
        module: {
            rules: [
                {
                    test: /\.css$/i,
                    use: [MiniCssExtractPlugin.loader, 'css-loader'],
                },
            ],
        },
        plugins: [
            new MiniCssExtractPlugin({ filename: '[name].css' }),
        ],
        optimization: {
            minimize: isProduction,
            minimizer: [new TerserPlugin({ extractComments: false })],
            splitChunks: false,
            runtimeChunk: false,
        },
        devtool: isProduction ? false : 'source-map',
        performance: { hints: false },
    };
};
