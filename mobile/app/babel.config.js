module.exports = function (api) {
  api.cache(true);

  return {
    presets: ['babel-preset-expo'],
    plugins: [
      // `@/…` is NOT aliased here on purpose. Expo SDK 53's Metro reads
      // `paths` straight out of tsconfig.json, so a babel alias would be a
      // second, silently-diverging copy of the same mapping - and the version
      // that ran at runtime would not be the one the type-checker used.
      //
      // Must stay LAST: Reanimated's plugin rewrites worklets and expects to
      // see the final AST.
      'react-native-reanimated/plugin',
    ],
  };
};
