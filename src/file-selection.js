/**
 * External dependencies
 */
import head from 'lodash/head';
import React, { Fragment, PureComponent } from 'react';
import { withRouter } from 'react-router'
import { Button, DropZoneProvider, DropZone } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { withDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import FileInput from './file-input';

const validFileTypes = Object.freeze( [ 'text/xml' ] );
const isValidFileType = type => validFileTypes.includes( type );

class FileSelection extends PureComponent {
	state = {
		hasDropped: false,
	};

	doIt = async ( files = [] ) => {
		const { setUploadResult } = this.props;
		const file = head( files );
		const { type, size } = file;

		if ( ! size ) {
			console.error( 'Cannot upload an empty file' );
			return;
		}

		if ( ! isValidFileType( type ) ) {
			console.error( `File type ${ type } is not supported` );
			return;
		}

		this.setState( { hasDropped: true } );

		const body = new FormData();
		body.append( 'import', file );
		body.append( 'status', 'private' );

		apiFetch( {
			method: 'POST',
			path: '/wordpress-importer/v1/attachment',
			body,
		} )
			.then( r => {
				console.log( r );
				setUploadResult( r );
				this.props.history.push( '/map' );
			} )
			.catch( e => console.error( e ) );
	};

	render() {
		const { setState, state } = this;
		const { hasDropped } = state;

		return (
			<Fragment>
				<DropZoneProvider>
					<h2>Import WordPress</h2>
					<div>Howdy! Upload your WordPress eXtended RSS (WXT) file and we'll import the posts, pages, comments, custom fields, categories, and tags into this site.</div>
					<div>Choose a WXR (.xml) file to upload, or drop a file here, and your import will begin</div>
					<FileInput onFileSelected={ this.doIt }>Choose file</FileInput>
					<div>
						<DropZone 
							onFilesDrop={ this.doIt }
							onHTMLDrop={ this.doIt }
							onDrop={ this.doIt }
						/>
					</div>
				</DropZoneProvider>
			</Fragment>
		);
	}
}

export default withDispatch( ( dispatch ) => {
	return {
		setUploadResult: dispatch( 'wordpress-importer' ).setUploadResult,
	};
} )( withRouter( FileSelection ) );
