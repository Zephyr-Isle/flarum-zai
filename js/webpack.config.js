const config = require('flarum-webpack-config')();

config.entry = {
    forum: './src/forum/index.tsx',
    admin: './src/admin/index.tsx',
};

module.exports = config;
