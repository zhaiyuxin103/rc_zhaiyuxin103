export default {
    '**/*.php': ['composer lint', 'composer types:check'],
    '**/*.{js,jsx,ts,tsx}': ['eslint --fix', 'prettier --write'],
    '**/*.{css,scss}': 'prettier --write',
};
