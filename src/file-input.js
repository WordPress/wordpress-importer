/**
 * External dependencies
 */
import React, { Fragment, PureComponent } from 'react';
import { Button } from '@wordpress/components';

import './style.scss';

class FileInput extends PureComponent {
	constructor( props ) {
		super( props );
		this.fileInput = React.createRef();
	}

	onChange = () => {
		this.fileInput.current && this.props.onFileSelected( this.fileInput.current.files );
	};

	onClick = () => {
		this.fileInput.current && this.fileInput.current.click();
	};

	render() {
		return (
			<Fragment>
				<input
					onChange={ this.onChange }
					ref={ this.fileInput }
					style={ { display: 'none' } } 
					type="file"
				/>
				<Button isPrimary onClick={ this.onClick }>{ this.props.children }</Button>
			</Fragment>
		);
	}
}

export default FileInput;
