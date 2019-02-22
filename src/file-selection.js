/**
 * External dependencies
 */
import head from 'lodash/head';
import React, { Fragment, PureComponent } from 'react';
import { withRouter } from 'react-router'
import { Button, DropZoneProvider, DropZone, Icon, TextControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { withDispatch, withSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import FileInput from './file-input';

const validFileTypes = Object.freeze( [ 'text/xml' ] );
const isValidFileType = type => validFileTypes.includes( type );

class FileSelection extends PureComponent {
	state = {
		isFetching: false,
		file: null,
		url: '',
	};

	beginImport = () => {
		const { setUploadResult } = this.props;
		const { file, url } = this.state;

		if ( file ) {
			this.setState( {
				isFetching: true,
			} );

			const body = new FormData();
			body.append( 'import', file );
			body.append( 'status', 'private' );

			apiFetch( {
				method: 'POST',
				path: '/wordpress-importer/v1/attachment',
				body,
			} )
				.then( response => {
					console.log( { response } );

					this.setState( { isFetching: false } );
					setUploadResult( response );
					this.props.history.push( '/map' );
				} )
				.catch( error => {
					this.setState( { isFetching: false } );
					console.error( { error } )
				} );
		} else {
			//@TODO logic for handling URL input here
		}
	};

	handleFileSelection = async ( files = [] ) => {
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

		this.setState( { file } );
		console.log ( file );
	};

	render() {
		const { isFetching, file, url } = this.state;

		// Only accept files if one isn't already selected
		const DropZoneTarget = file ? Fragment : DropZoneProvider;

		return (
			<Fragment>
				<DropZoneTarget>
					<h2>Import WordPress</h2>
					<div>Howdy! Upload your WordPress eXtended RSS (WXT) file and we'll import the posts, pages, comments, custom fields, categories, and tags into this site.</div>
					<div>Choose a WXR (.xml) file to upload, or drop a file here, and your import will begin</div>
					{ ! url && (
						<div className="wordpress-importer__div-actions">
							Import from file:
							{ file
								? ( <span>{ file.name } <Button onClick={ () => this.setState( { file: null } ) } isLink><Icon icon="no" /></Button></span> )
								: ( <FileInput onFileSelected={ this.handleFileSelection }>Choose file</FileInput> )
							}
						</div>
					) }
					{ ! file && (
						<div className="wordpress-importer__div-actions">
							<TextControl
								label="Import from url:"
								onChange={ ( url ) => this.setState( { url } ) }
								value={ url }
							/>
						</div>
					) }
					<div>
						<DropZone
							onFilesDrop={ this.handleFileSelection }
							onHTMLDrop={ this.handleFileSelection }
							onDrop={ this.handleFileSelection }
						/>
					</div>

					<div className="wordpress-importer__div-actions">
						{ isFetching
							? ( <span>Loading…</span> )
							: ( <Button onClick={ this.beginImport } isPrimary>Begin Import</Button> )
						}
					</div>
				</DropZoneTarget>
			</Fragment>
		);
	}
}

export default withSelect( ( select ) => {
	return {
		// Prefetch siteAuthors so they're ready for the next step
		siteAuthors: select( 'core' ).getAuthors(),
	};
} )( withDispatch( ( dispatch ) => {
	return {
		setUploadResult: dispatch( 'wordpress-importer' ).setUploadResult,
	};
} )( withRouter( FileSelection ) ) );
