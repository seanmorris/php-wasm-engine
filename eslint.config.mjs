import smNoSaccadeStyle from 'sm-no-saccade-style';

export default [
	{
		ignores: [
			'node_modules/**'
			, 'vendor/**'
			, '.runtime/**'
			, '.runtime-staging/**'
			, 'docs/dist/**'
			, 'docs/runtime/**'
			, 'docs/engine/**'
			, 'test-results/**'
			, 'playwright-report/**'
		]
	}
	, ...smNoSaccadeStyle.configs.recommended
	, {
		rules: {
			'sm-no-saccade-style/max-line-length': ['error', {max: 120, tabWidth: 4}]
			, 'sm-no-saccade-style/allman-tabs': ['error', {maxLineLength: 120}]
			, 'sm-no-saccade-style/parameter-layout': ['error', {max: 120}]
		}
	}
];
