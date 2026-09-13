export const writeConverterMessage = (stream, payload) => new Promise((resolve, reject) => {
  stream.write(`${JSON.stringify(payload)}\n`, (error) => error ? reject(error) : resolve());
});
