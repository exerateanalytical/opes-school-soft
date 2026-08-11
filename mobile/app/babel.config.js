module.exports = function (api) {
  api.cache(true);

  return {
    presets: ['babel-preset-expo'],
    plugins: [
      // Keeps `@/…` imports resolving the same way tsconfig `paths` does, so a
      // moved file breaks the type-check rather than only the runtime.
      ['module-resolver', { alias: { '@': './src' } }],
      // Must stay LAST - Reanimated's plugin rewrites worklets and expects to
      // see the final AST.
      'react-native-reanimated/plugin',
    ],
  };
};
